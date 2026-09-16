<?php

namespace App\Actions\Auth;

use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterUser
{
    /**
     * Register a new user, create their tenant workspace, and generate a Sanctum token.
     *
     * @param array<string, mixed> $data
     * @return array{user: User, token: string, tenant: Tenant}
     */
    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // 1. Create User
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // Hashed by the model cast
                'status' => User::STATUS_ACTIVE,
            ]);

            // 2. Fetch default plan
            $defaultPlan = Plan::where('slug', 'basic')->first() ?? Plan::first();

            // 3. Create Tenant
            $companyName = $data['company_name'];
            $tenant = TenantContext::withoutTenancy(function () use ($companyName, $defaultPlan, $data) {
                return Tenant::create([
                    'name' => $companyName,
                    'slug' => Str::slug($companyName) . '-' . Str::random(5),
                    'status' => Tenant::STATUS_TRIAL,
                    'plan_id' => $defaultPlan?->id,
                    'settings' => [
                        'company_email' => $data['company_email'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'country' => $data['country'] ?? null,
                        'industry' => $data['industry'] ?? null,
                        'website' => $data['website'] ?? null,
                    ],
                ]);
            });

            // 4. Attach user as Tenant Owner
            $ownerRole = Role::where('slug', Role::TENANT_OWNER)->first();
            $tenant->users()->attach($user->id, [
                'role_id' => $ownerRole?->id,
                'is_owner' => true,
            ]);

            // 5. Set as user's current tenant
            $user->forceFill(['current_tenant_id' => $tenant->id])->save();

            event(new Registered($user));

            // 6. Generate access token
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
