<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    /**
     * Determine whether the user can view the tenant details.
     */
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant->id) || $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can update tenant details.
     */
    public function update(User $user, Tenant $tenant): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->belongsToTenant($tenant->id) && $user->hasPermission('manage_settings');
    }

    /**
     * Determine whether the user can manage tenant users.
     */
    public function manageUsers(User $user, Tenant $tenant): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->belongsToTenant($tenant->id) && $user->hasPermission('manage_users');
    }

    /**
     * Determine whether the user can manage tenant billing.
     */
    public function manageBilling(User $user, Tenant $tenant): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->belongsToTenant($tenant->id) && $user->hasPermission('manage_billing');
    }
}
