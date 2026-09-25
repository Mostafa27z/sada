<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Tenant;
use App\Services\MarketingConsultantAiService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatMessageController extends Controller
{
    use ApiResponse;

    /**
     * Get paginated messages for a chat room.
     *
     * @param Request $request
     * @param int $roomId
     * @return JsonResponse
     */
    public function index(Request $request, int $roomId): JsonResponse
    {
        $room = ChatRoom::find($roomId);

        if (!$room) {
            return $this->error('غرفة المحادثة غير موجودة', 404);
        }

        if (!$room->hasUser($request->user()->id)) {
            return $this->error('ليس لديك صلاحية الوصول إلى هذه الغرفة', 403);
        }

        $messages = $room->messages()
            ->with('user')
            ->latest()
            ->paginate($request->input('per_page', 30));

        return $this->paginated($messages, ChatMessageResource::class);
    }

    /**
     * Send a message to a chat room.
     * If the message mentions `@ai`, the AI Marketing Consultant analyzes tenant data and responds.
     *
     * @param SendChatMessageRequest $request
     * @param int $roomId
     * @param MarketingConsultantAiService $aiService
     * @return JsonResponse
     */
    public function send(
        SendChatMessageRequest $request,
        int $roomId,
        MarketingConsultantAiService $aiService
    ): JsonResponse {
        $room = ChatRoom::find($roomId);

        if (!$room) {
            return $this->error('غرفة المحادثة غير موجودة', 404);
        }

        $user = $request->user();
        if (!$room->hasUser($user->id)) {
            return $this->error('ليس لديك صلاحية الإرسال في هذه الغرفة', 403);
        }

        $messageText = trim($request->input('message'));

        // 1. Save staff member's message
        $userMessage = ChatMessage::create([
            'chat_room_id' => $room->id,
            'user_id' => $user->id,
            'sender_type' => 'user',
            'message' => $messageText,
        ]);
        $userMessage->load('user');

        $aiMessage = null;

        // 2. Check if user mentioned `@ai` (case-insensitive) and AI is enabled
        $mentionsAi = (bool) preg_match('/(^|\s)@ai(\b|[^\w])/iu', $messageText) || str_contains(mb_strtolower($messageText), '@ai');

        if ($mentionsAi && $room->is_ai_enabled) {
            $tenantId = TenantContext::getTenantId() ?: $room->tenant_id;
            $tenant = Tenant::find($tenantId);

            if ($tenant) {
                // Call Marketing Consultant AI with full tenant context
                $aiResult = $aiService->consult($tenant, $room, $user, $messageText);

                $aiMessage = ChatMessage::create([
                    'chat_room_id' => $room->id,
                    'user_id' => null,
                    'sender_type' => 'ai',
                    'message' => $aiResult['response'],
                    'thinking_steps' => $aiResult['thinking_steps'],
                    'metadata' => $aiResult['metadata'],
                ]);
            }
        }

        return $this->created([
            'user_message' => new ChatMessageResource($userMessage),
            'ai_message' => $aiMessage ? new ChatMessageResource($aiMessage) : null,
        ], 'تم إرسال الرسالة بنجاح');
    }
}
