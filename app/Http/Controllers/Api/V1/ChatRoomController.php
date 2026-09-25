<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\AddChatRoomUsersRequest;
use App\Http\Requests\Chat\StoreChatRoomRequest;
use App\Http\Resources\ChatRoomResource;
use App\Models\ChatRoom;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatRoomController extends Controller
{
    use ApiResponse;

    /**
     * List all chat rooms the current user has access to in this tenant.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $rooms = ChatRoom::with(['creator', 'users'])
            ->with(['messages' => fn ($q) => $q->latest()->take(1)])
            ->where(function ($q) use ($userId) {
                $q->where('created_by', $userId)
                  ->orWhereHas('users', fn ($uq) => $uq->where('users.id', $userId));
            })
            ->latest()
            ->paginate($request->input('per_page', 20));

        return $this->paginated($rooms, ChatRoomResource::class);
    }

    /**
     * Create a new team chat room with optional initial members.
     *
     * @param StoreChatRoomRequest $request
     * @return JsonResponse
     */
    public function store(StoreChatRoomRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $user = $request->user();

        $room = ChatRoom::create([
            'tenant_id' => $tenantId,
            'created_by' => $user->id,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'is_ai_enabled' => $request->input('is_ai_enabled', true),
            'ai_consultant_name' => $request->input('ai_consultant_name', 'مستشار التسويق الذكي'),
        ]);

        // Automatically attach the creator as admin
        $room->users()->syncWithoutDetaching([
            $user->id => ['role' => 'admin'],
        ]);

        // Attach other specified users
        if ($request->has('user_ids') && is_array($request->input('user_ids'))) {
            $otherUsers = array_diff($request->input('user_ids'), [$user->id]);
            if (!empty($otherUsers)) {
                $attachData = [];
                foreach ($otherUsers as $uId) {
                    $attachData[$uId] = ['role' => 'member'];
                }
                $room->users()->syncWithoutDetaching($attachData);
            }
        }

        $room->load(['creator', 'users']);

        return $this->created(new ChatRoomResource($room), 'تم إنشاء غرفة المحادثة بنجاح');
    }

    /**
     * Show chat room details.
     *
     * @param int $id
     * @param Request $request
     * @return JsonResponse
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $room = ChatRoom::with(['creator', 'users'])->find($id);

        if (!$room) {
            return $this->error('غرفة المحادثة غير موجودة', 404);
        }

        if (!$room->hasUser($request->user()->id)) {
            return $this->error('ليس لديك صلاحية الوصول إلى هذه الغرفة', 403);
        }

        return $this->success(new ChatRoomResource($room));
    }

    /**
     * Update chat room.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $room = ChatRoom::find($id);

        if (!$room) {
            return $this->error('غرفة المحادثة غير موجودة', 404);
        }

        if ($room->created_by !== $request->user()->id) {
            return $this->error('فقط منشئ الغرفة أو المشرف يمكنه التعديل', 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_ai_enabled' => ['sometimes', 'boolean'],
            'ai_consultant_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $room->update($validated);
        $room->load(['creator', 'users']);

        return $this->success(new ChatRoomResource($room), 'تم تحديث بيانات الغرفة بنجاح');
    }

    /**
     * Delete chat room.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $room = ChatRoom::find($id);

        if (!$room) {
            return $this->error('غرفة المحادثة غير موجودة', 404);
        }

        if ($room->created_by !== $request->user()->id) {
            return $this->error('فقط منشئ الغرفة يمكنه حذفها', 403);
        }

        $room->delete();

        return $this->success(null, 'تم حذف غرفة المحادثة بنجاح');
    }

    /**
     * Add users to an existing chat room.
     *
     * @param AddChatRoomUsersRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function addUsers(AddChatRoomUsersRequest $request, int $id): JsonResponse
    {
        $room = ChatRoom::find($id);

        if (!$room) {
            return $this->error('غرفة المحادثة غير موجودة', 404);
        }

        if (!$room->hasUser($request->user()->id)) {
            return $this->error('ليس لديك صلاحية إدارة الأعضاء في هذه الغرفة', 403);
        }

        $userIds = $request->input('user_ids');
        $attachData = [];
        foreach ($userIds as $uId) {
            $attachData[$uId] = ['role' => 'member'];
        }

        $room->users()->syncWithoutDetaching($attachData);
        $room->load('users');

        return $this->success(new ChatRoomResource($room), 'تمت إضافة الأعضاء بنجاح');
    }

    /**
     * Remove a user from the chat room.
     *
     * @param Request $request
     * @param int $id
     * @param int $userId
     * @return JsonResponse
     */
    public function removeUser(Request $request, int $id, int $userId): JsonResponse
    {
        $room = ChatRoom::find($id);

        if (!$room) {
            return $this->error('غرفة المحادثة غير موجودة', 404);
        }

        // Allow user to leave, or creator to remove member
        $currentUserId = $request->user()->id;
        if ($currentUserId !== $room->created_by && $currentUserId !== $userId) {
            return $this->error('ليس لديك صلاحية حذف هذا العضو', 403);
        }

        if ($userId === $room->created_by) {
            return $this->error('لا يمكن إزالة منشئ الغرفة', 400);
        }

        $room->users()->detach($userId);
        $room->load('users');

        return $this->success(new ChatRoomResource($room), 'تم حذف العضو من الغرفة بنجاح');
    }
}
