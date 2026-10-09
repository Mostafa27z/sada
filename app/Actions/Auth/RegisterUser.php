<?php

namespace App\Actions\Auth;

use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RegisterUser
{
    /**
     * Store uploaded file or base64 image and return public URL or path.
     */
    protected function storeImage(mixed $image, string $directory): ?string
    {
        if (!$image) {
            return null;
        }

        if ($image instanceof UploadedFile) {
            $path = $image->store($directory, 'public');
            return Storage::disk('public')->url($path);
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
                    Storage::disk('public')->put($filename, $decoded);
                    return Storage::disk('public')->url($filename);
                }
            }

            return $image;
        }

        return null;
    }

    /**
     * Register a new user, create their tenant workspace, and generate a Sanctum token.
     *
     * @param array<string, mixed> $data
     * @return array{user: User, token: string, tenant: Tenant}
     */
    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // Process logo and avatar
            $logoUrl = $this->storeImage($data['logo'] ?? null, 'logos');
            $avatarUrl = $this->storeImage($data['avatar'] ?? null, 'avatars');

            // 1. Create User (Suspended until Super Admin reviews and approves)
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // Hashed by the model cast
                'avatar' => $avatarUrl,
                'status' => User::STATUS_SUSPENDED,
            ]);

            // 2. Create Tenant (Suspended, pending Super Admin review)
            $companyName = $data['company_name'];
            $tenant = TenantContext::withoutTenancy(function () use ($companyName, $data, $logoUrl) {
                return Tenant::create([
                    'name' => $companyName,
                    'slug' => Str::slug($companyName) . '-' . Str::random(5),
                    'logo' => $logoUrl,
                    'status' => Tenant::STATUS_SUSPENDED,
                    'plan_id' => null,
                    'trial_ends_at' => null,
                    'settings' => [
                        'company_email'       => $data['company_email'] ?? null,
                        'phone'               => $data['phone'] ?? null,
                        'country'             => $data['country'] ?? null,
                        'city'                => $data['city'] ?? null,
                        'industry'            => $data['industry'] ?? null,
                        'company_description' => $data['company_description'] ?? null,
                        'website'             => $data['website'] ?? null,
                    ],
                ]);
            });

            // 3. Attach user as Tenant Owner
            $ownerRole = Role::where('slug', Role::TENANT_OWNER)->first();
            $tenant->users()->attach($user->id, [
                'role_id' => $ownerRole?->id,
                'is_owner' => true,
            ]);

            // 4. Set as user's current tenant
            $user->forceFill(['current_tenant_id' => $tenant->id])->save();

            try {
                event(new Registered($user));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to send registration email: ' . $e->getMessage());
            }

            // Note: InitializeTenantIntelligenceJob is triggered only AFTER Super Admin approves the tenant.

            // 5. Generate access token
            $token = $user->createToken(
                config('sada.token.name', 'api-token')
            )->plainTextToken;

            return [
                'user' => $user->fresh(['currentTenant']),
                'token' => $token,
                'tenant' => $tenant,
            ];
        });
    }
}
