<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use ApiResponse;

    /**
     * List all available roles for current tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $roles = Role::whereNull('tenant_id')
            ->when($tenantId, function ($query) use ($tenantId) {
                $query->orWhere('tenant_id', $tenantId);
            })
            ->with('permissions')
            ->get();

        return $this->success(RoleResource::collection($roles));
    }

    /**
     * List all permissions in system.
     */
    public function permissions(Request $request): JsonResponse
    {
        $permissions = Permission::all();

        return $this->success(PermissionResource::collection($permissions));
    }
}
