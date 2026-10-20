<?php

namespace App\Services;

use App\Models\Role;
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
            if (preg_match('/^data:image\/([a-zA-Z0-9\+\-\.]+);base64,/', $image, $type)) {
                $data = substr($image, strpos($image, ',') + 1);
                $ext = strtolower($type[1]);
                if (str_contains($ext, 'svg')) {
                    $ext = 'svg';
                } elseif ($ext === 'jpeg') {
                    $ext = 'jpg';
                }
                $decoded = base64_decode(str_replace(' ', '+', $data));
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
     * Invite / attach a user to a tenant with optional direct password and role.
     */
    public function inviteUser(
        Tenant $tenant,
        string $email,
        ?string $name = null,
        ?string $phone = null,
        ?string $status = null,
        mixed $avatar = null,
        ?string $password = null,
        ?string $role = null
    ): User {
        return DB::transaction(function () use ($tenant, $email, $name, $phone, $status, $avatar, $password, $role) {
            $user = User::where('email', $email)->first();
            $avatarUrl = $this->storeImage($avatar, 'avatars');

            $hasExplicitPassword = !empty($password);
            $hashedPassword = $hasExplicitPassword ? bcrypt($password) : bcrypt(Str::random(16));

            if (!$user) {
                $user = User::create([
                    'name' => $name ?? explode('@', $email)[0],
                    'email' => $email,
                    'phone' => $phone,
                    'avatar' => $avatarUrl,
                    'password' => $hashedPassword,
                    'status' => $status ?? User::STATUS_ACTIVE,
                    'email_verified_at' => $hasExplicitPassword ? now() : null,
                ]);
            } else {
                $updateData = [];
                if ($phone !== null) $updateData['phone'] = $phone;
                if ($name !== null) $updateData['name'] = $name;
                if ($status !== null) $updateData['status'] = $status;
                if ($avatarUrl !== null) $updateData['avatar'] = $avatarUrl;
                if ($hasExplicitPassword) {
                    $updateData['password'] = $hashedPassword;
                    $updateData['email_verified_at'] = now();
                    $updateData['status'] = User::STATUS_ACTIVE;
                }
                if (!empty($updateData)) {
                    $user->update($updateData);
                }
            }

            // Resolve role (prevent assigning SUPER_ADMIN to tenant user)
            $roleId = null;
            if ($role) {
                $roleModel = is_numeric($role)
                    ? Role::where('slug', '!=', Role::SUPER_ADMIN)->find($role)
                    : Role::where('slug', '!=', Role::SUPER_ADMIN)
                        ->where('slug', $role)
                        ->where(function ($sub) use ($tenant) {
                            $sub->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id);
                        })->first();
                $roleId = $roleModel?->id;
            }

            if (!$user->belongsToTenant($tenant->id)) {
                $tenant->users()->attach($user->id, [
                    'role_id' => $roleId,
                    'is_owner' => false,
                ]);
            } elseif ($roleId) {
                $tenant->users()->updateExistingPivot($user->id, [
                    'role_id' => $roleId,
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
        return DB::transaction(function () use ($tenant, $user, $data) {
            if (isset($data['avatar'])) {
                $data['avatar'] = $this->storeImage($data['avatar'], 'avatars');
            }

            $fillable = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'status', 'avatar']));

            // Direct password change
            if (!empty($data['password'])) {
                $fillable['password'] = bcrypt($data['password']);
                $fillable['email_verified_at'] = now();
                $fillable['status'] = User::STATUS_ACTIVE;
            }

            if (!empty($fillable)) {
                $user->update($fillable);
            }

            // Update role assignment in tenant_user pivot (prevent assigning SUPER_ADMIN)
            if (isset($data['role']) && !empty($data['role'])) {
                $role = $data['role'];
                $roleModel = is_numeric($role)
                    ? Role::where('slug', '!=', Role::SUPER_ADMIN)->find($role)
                    : Role::where('slug', '!=', Role::SUPER_ADMIN)
                        ->where('slug', $role)
                        ->where(function ($sub) use ($tenant) {
                            $sub->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id);
                        })->first();

                if ($roleModel) {
                    $tenant->users()->updateExistingPivot($user->id, [
                        'role_id' => $roleModel->id,
                    ]);
                }
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
