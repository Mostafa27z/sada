<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AssignRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserRoleController extends Controller
{
    use ApiResponse;

    /**
     * Assign role to user inside current tenant workspace.
     */
    public function assignRole(AssignRoleRequest $request, int $userId): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $user = TenantContext::withoutTenancy(function () use ($userId) {
            return User::find($userId);
        });

        if (!$user || !$user->belongsToTenant($tenant->id)) {
            return $this->error(__('messages.not_found'), 404);
        }

        $user->assignRole($request->validated('role'), $tenant->id);

        return $this->success(
            new UserResource($user->fresh()),
            __('messages.role_assigned')
        );
    }
}
