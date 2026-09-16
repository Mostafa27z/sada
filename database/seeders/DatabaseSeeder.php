<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Run Core Seeders
        $this->call([
            PlanSeeder::class,
            RoleAndPermissionSeeder::class,
            SuperAdminSeeder::class,
        ]);

        // 2. Fetch Pro Plan
        $proPlan = Plan::where('slug', 'pro')->first();

        // 3. Create Default Tenant
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'sada-tech'],
            [
                'ulid' => (string) Str::ulid(),
                'name' => 'شركة صدى للتقنية',
                'status' => Tenant::STATUS_ACTIVE,
                'plan_id' => $proPlan?->id,
            ]
        );

        // Define password
        $password = Hash::make('password');

        // 4. Create and Attach Users with Different Roles

        // A. Super Admin
        $superAdmin = User::updateOrCreate(
            ['email' => 'superadmin@sada.com'],
            [
                'name' => 'مدير النظام الفائق',
                'password' => $password,
                'email_verified_at' => now(),
                'status' => User::STATUS_ACTIVE,
                'current_tenant_id' => $tenant->id,
            ]
        );
        $superAdminRole = Role::where('slug', Role::SUPER_ADMIN)->first();
        if ($superAdminRole) {
            $tenant->users()->syncWithoutDetaching([
                $superAdmin->id => [
                    'role_id' => $superAdminRole->id,
                    'is_owner' => false,
                ]
            ]);
        }

        // B. Tenant Owner
        $owner = User::updateOrCreate(
            ['email' => 'owner@sada.com'],
            [
                'name' => 'مالك مساحة العمل',
                'password' => $password,
                'email_verified_at' => now(),
                'status' => User::STATUS_ACTIVE,
                'current_tenant_id' => $tenant->id,
            ]
        );
        $ownerRole = Role::where('slug', Role::TENANT_OWNER)->first();
        $tenant->users()->syncWithoutDetaching([
            $owner->id => [
                'role_id' => $ownerRole?->id,
                'is_owner' => true,
            ]
        ]);

        // C. Tenant Admin
        $admin = User::updateOrCreate(
            ['email' => 'admin@sada.com'],
            [
                'name' => 'مدير النظام للمؤسسة',
                'password' => $password,
                'email_verified_at' => now(),
                'status' => User::STATUS_ACTIVE,
                'current_tenant_id' => $tenant->id,
            ]
        );
        $adminRole = Role::where('slug', Role::TENANT_ADMIN)->first();
        if ($adminRole) {
            $tenant->users()->syncWithoutDetaching([
                $admin->id => [
                    'role_id' => $adminRole->id,
                    'is_owner' => false,
                ]
            ]);
        }

        // D. Analyst
        $analyst = User::updateOrCreate(
            ['email' => 'analyst@sada.com'],
            [
                'name' => 'المحلل الإعلامي',
                'password' => $password,
                'email_verified_at' => now(),
                'status' => User::STATUS_ACTIVE,
                'current_tenant_id' => $tenant->id,
            ]
        );
        $analystRole = Role::where('slug', Role::ANALYST)->first();
        if ($analystRole) {
            $tenant->users()->syncWithoutDetaching([
                $analyst->id => [
                    'role_id' => $analystRole->id,
                    'is_owner' => false,
                ]
            ]);
        }

        // E. Viewer
        $viewer = User::updateOrCreate(
            ['email' => 'viewer@sada.com'],
            [
                'name' => 'المستعرض',
                'password' => $password,
                'email_verified_at' => now(),
                'status' => User::STATUS_ACTIVE,
                'current_tenant_id' => $tenant->id,
            ]
        );
        $viewerRole = Role::where('slug', Role::VIEWER)->first();
        if ($viewerRole) {
            $tenant->users()->syncWithoutDetaching([
                $viewer->id => [
                    'role_id' => $viewerRole->id,
                    'is_owner' => false,
                ]
            ]);
        }

        // 5. Seed Constant Global Sources
        $constantSources = [
            [
                'name' => 'تيك توك / TikTok',
                'slug' => 'tiktok',
                'type' => 'tiktok',
                'url' => 'https://tiktok.com',
            ],
            [
                'name' => 'فيسبوك / Facebook',
                'slug' => 'facebook',
                'type' => 'facebook',
                'url' => 'https://facebook.com',
            ],
            [
                'name' => 'إنستغرام / Instagram',
                'slug' => 'instagram',
                'type' => 'instagram',
                'url' => 'https://instagram.com',
            ],
            [
                'name' => 'تويتر (إكس) / Twitter (X)',
                'slug' => 'twitter-x',
                'type' => 'twitter (x)',
                'url' => 'https://x.com',
            ],
            [
                'name' => 'الويب / Web',
                'slug' => 'web',
                'type' => 'web',
                'url' => 'https://google.com',
            ],
        ];

        foreach ($constantSources as $sourceData) {
            \App\Models\Source::updateOrCreate(
                ['slug' => $sourceData['slug'], 'tenant_id' => null],
                $sourceData
            );
        }
    }
}
