<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenants\InviteTenantUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\TenantService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantUserController extends Controller
{
    use ApiResponse;

    public function __construct(protected TenantService $tenantService)
    {
    }

    /**
     * List all users in the current active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $users = TenantContext::withoutTenancy(function () use ($tenant) {
            return $tenant->users()->get();
        });

        return $this->success(UserResource::collection($users));
    }

    /**
     * Invite / add a user to the current active tenant.
     */
    public function invite(InviteTenantUserRequest $request): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $user = $this->tenantService->inviteUser(
            $tenant,
            $request->validated('email'),
            $request->validated('name'),
            $request->validated('phone'),
            $request->validated('status')
        );

        return $this->created(
            new UserResource($user),
            __('messages.user_invited')
        );
    }

    /**
     * Update user details in the tenant context.
     */
    public function update(Request $request, int $userId): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $userToUpdate = TenantContext::withoutTenancy(function () use ($userId) {
            return User::find($userId);
        });

        if (!$userToUpdate || !$userToUpdate->belongsToTenant($tenant->id)) {
            return $this->error(__('messages.not_found'), 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,inactive,suspended,invited'],
        ]);

        $updatedUser = $this->tenantService->updateUser($tenant, $userToUpdate, $data);

        return $this->success(
            new UserResource($updatedUser),
            __('messages.updated_successfully')
        );
    }

    /**
     * Remove a user from the current active tenant.
     */
    public function remove(Request $request, int $userId): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $userToRemove = TenantContext::withoutTenancy(function () use ($userId) {
            return User::find($userId);
        });

        if (!$userToRemove || !$userToRemove->belongsToTenant($tenant->id)) {
            return $this->error(__('messages.not_found'), 404);
        }

        // Prevent removing tenant owner
        if ($tenant->owner()?->id === $userToRemove->id) {
            return $this->error(__('messages.cannot_remove_owner'), 400);
        }

        $this->tenantService->removeUser($tenant, $userToRemove);

        return $this->success(null, __('messages.user_removed'));
    }
}
