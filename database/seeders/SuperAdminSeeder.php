<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure Roles are seeded first
        $this->call(RoleAndPermissionSeeder::class);

        $superAdminRole = Role::where('slug', Role::SUPER_ADMIN)->first();

        // 2. System Tenant
        $systemTenant = Tenant::firstOrCreate(
            ['slug' => 'sada-system'],
            [
                'ulid' => (string) Str::ulid(),
                'name' => 'إدارة منصة صدى',
                'status' => 'active',
                'plan_id' => 1,
            ]
        );

        // 3. Super Admin User
        $user = User::firstOrCreate(
            ['email' => 'admin@sada.ai'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('AdminPassword123!'),
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ]
        );

        // 4. Attach Super Admin Role to User
        DB::table('tenant_user')->updateOrInsert(
            [
                'tenant_id' => $systemTenant->id,
                'user_id' => $user->id,
            ],
            [
                'role_id' => $superAdminRole->id,
                'is_owner' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $user->current_tenant_id = $systemTenant->id;
        $user->save();
    }
}
