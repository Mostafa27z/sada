<?php

namespace App\Models\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Support\TenantContext;

trait HasRolesAndPermissions
{
    /**
     * Get the active role for the user in the current tenant workspace.
     */
    public function currentRole(): ?Role
    {
        $tenantId = TenantContext::getTenantId() ?? $this->current_tenant_id;

        if (!$tenantId) {
            return null;
        }

        $pivot = TenantContext::withoutTenancy(function () use ($tenantId) {
            return $this->tenantUsers()->where('tenant_id', $tenantId)->first();
        });

        if ($pivot && $pivot->role_id) {
            return TenantContext::withoutTenancy(fn () => Role::find($pivot->role_id));
        }

        if ($pivot && $pivot->is_owner) {
            return TenantContext::withoutTenancy(fn () => Role::where('slug', Role::TENANT_OWNER)->first());
        }

        return null;
    }

    /**
     * Check if user has a specific role in current active tenant.
     */
    public function hasRole(string $roleSlug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $role = $this->currentRole();

        return $role !== null && $role->slug === $roleSlug;
    }

    /**
     * Check if user has a specific permission in current active tenant.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $role = $this->currentRole();

        if (!$role) {
            return false;
        }

        return TenantContext::withoutTenancy(function () use ($role, $permissionSlug) {
            return $role->permissions->contains('slug', $permissionSlug);
        });
    }

    /**
     * Assign a role to user in the active tenant workspace.
     */
    public function assignRole(Role|string $role, ?int $tenantId = null): void
    {
        $targetTenantId = $tenantId ?? TenantContext::getTenantId() ?? $this->current_tenant_id;

        if (!$targetTenantId) {
            return;
        }

        $roleModel = is_string($role) ? Role::where('slug', $role)->first() : $role;

        if ($roleModel) {
            $this->tenants()->updateExistingPivot($targetTenantId, [
                'role_id' => $roleModel->id,
            ]);
        }
    }
}
