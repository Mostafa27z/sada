<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantService
{
    /**
     * Create a new tenant workspace and attach owner user.
     */
    public function createTenant(User $owner, array $data): Tenant
    {
        return DB::transaction(function () use ($owner, $data) {
            $tenant = TenantContext::withoutTenancy(function () use ($data) {
                return Tenant::create([
                    'name' => $data['name'],
                    'slug' => $data['slug'] ?? Str::slug($data['name']) . '-' . Str::random(4),
                    'logo' => $data['logo'] ?? null,
                    'status' => Tenant::STATUS_TRIAL,
                    'settings' => $data['settings'] ?? [],
                ]);
            });

            // Attach user as owner
            $tenant->users()->attach($owner->id, [
                'is_owner' => true,
            ]);

            // Set as user's current tenant if not set
            if (!$owner->current_tenant_id) {
                $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
            }

            return $tenant;
        });
    }

    /**
     * Invite / attach a user to a tenant.
     */
    public function inviteUser(Tenant $tenant, string $email, ?string $name = null): User
    {
        return DB::transaction(function () use ($tenant, $email, $name) {
            $user = User::where('email', $email)->first();

            if (!$user) {
                $user = User::create([
                    'name' => $name ?? explode('@', $email)[0],
                    'email' => $email,
                    'password' => bcrypt(Str::random(16)),
                    'status' => User::STATUS_INVITED,
                ]);
            }

            if (!$user->belongsToTenant($tenant->id)) {
                $tenant->users()->attach($user->id, [
                    'is_owner' => false,
                ]);
            }

            if (!$user->current_tenant_id) {
                $user->forceFill(['current_tenant_id' => $tenant->id])->save();
            }

            return $user;
        });
    }

    /**
     * Remove a user from a tenant.
     */
    public function removeUser(Tenant $tenant, User $user): void
    {
        DB::transaction(function () use ($tenant, $user) {
            $tenant->users()->detach($user->id);

            if ($user->current_tenant_id === $tenant->id) {
                $firstRemaining = $user->tenants()->first();
                $user->forceFill([
                    'current_tenant_id' => $firstRemaining?->id,
                ])->save();
            }
        });
    }
}
