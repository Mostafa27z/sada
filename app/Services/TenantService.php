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
     * Update tenant details, logo, and settings.
     */
    public function updateTenant(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data) {
            if (array_key_exists('logo', $data) && $data['logo']) {
                $data['logo'] = $this->storeImage($data['logo'], 'logos');
            }

            if (isset($data['settings']) && is_array($data['settings'])) {
                $currentSettings = is_array($tenant->settings) ? $tenant->settings : [];
                $data['settings'] = array_replace_recursive($currentSettings, $data['settings']);
            }

            $tenant->update($data);
            return $tenant->fresh();
        });
    }

    /**
     * Store uploaded file or base64 image and return public URL or path.
     */
    public function storeImage(mixed $image, string $directory): ?string
    {
        if (!$image) {
            return null;
        }

        if ($image instanceof \Illuminate\Http\UploadedFile) {
            $path = $image->store($directory, 'public');
            return \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        }

        if (is_string($image)) {
            if (preg_match('/^data:image\/(\w+);base64,/', $image, $type)) {
                $data = substr($image, strpos($image, ',') + 1);
                $ext = strtolower($type[1]);
                if ($ext === 'svg+xml') {
                    $ext = 'svg';
                }
                $decoded = base64_decode($data);
                if ($decoded !== false) {
                    $filename = $directory . '/' . Str::random(32) . '.' . $ext;
                    \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $decoded);
                    return \Illuminate\Support\Facades\Storage::disk('public')->url($filename);
                }
            }

            return $image;
        }

        return null;
    }

    /**
     * Invite / attach a user to a tenant.
     */
    public function inviteUser(Tenant $tenant, string $email, ?string $name = null, ?string $phone = null, ?string $status = null, mixed $avatar = null): User
    {
        return DB::transaction(function () use ($tenant, $email, $name, $phone, $status, $avatar) {
            $user = User::where('email', $email)->first();
            $avatarUrl = $this->storeImage($avatar, 'avatars');

            if (!$user) {
                $user = User::create([
                    'name' => $name ?? explode('@', $email)[0],
                    'email' => $email,
                    'phone' => $phone,
                    'avatar' => $avatarUrl,
                    'password' => bcrypt(Str::random(16)),
                    'status' => $status ?? User::STATUS_ACTIVE,
                ]);
            } else {
                $updateData = [];
                if ($phone !== null) $updateData['phone'] = $phone;
                if ($name !== null) $updateData['name'] = $name;
                if ($status !== null) $updateData['status'] = $status;
                if ($avatarUrl !== null) $updateData['avatar'] = $avatarUrl;
                if (!empty($updateData)) {
                    $user->update($updateData);
                }
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
     * Update user information in tenant context.
     */
    public function updateUser(Tenant $tenant, User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            if (isset($data['avatar'])) {
                $data['avatar'] = $this->storeImage($data['avatar'], 'avatars');
            }
            $fillable = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'status', 'avatar']));
            if (!empty($fillable)) {
                $user->update($fillable);
            }
            return $user->refresh();
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
