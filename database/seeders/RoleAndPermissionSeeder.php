<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create System Permissions
        $permissions = [
            // Dashboard
            ['name' => 'عرض لوحة التحكم', 'slug' => 'view_dashboard', 'category' => 'dashboard', 'description' => 'إمكانية عرض لوحة التحكم والإحصائيات'],

            // Keywords
            ['name' => 'إدارة الكلمات المفتاحية', 'slug' => 'manage_keywords', 'category' => 'keywords', 'description' => 'إنشاء وتعديل وحذف وحالة الكلمات المفتاحية'],

            // Sources
            ['name' => 'إدارة المصادر', 'slug' => 'manage_sources', 'category' => 'sources', 'description' => 'إضافة وتعديل وإدارة المصادر'],

            // Articles
            ['name' => 'عرض المقالات والأخبار', 'slug' => 'view_articles', 'category' => 'articles', 'description' => 'استعراض وقراءة المقالات والأخبار المترصدة'],
            ['name' => 'إدارة المقالات والأخبار', 'slug' => 'manage_articles', 'category' => 'articles', 'description' => 'حذف وتعديل وحفظ المقالات'],

            // Alerts
            ['name' => 'إدارة التنبيهات', 'slug' => 'manage_alerts', 'category' => 'alerts', 'description' => 'إعداد واستقبال وحل التنبيهات'],

            // Reports
            ['name' => 'إدارة التقارير', 'slug' => 'manage_reports', 'category' => 'reports', 'description' => 'إنشاء واستخراج وتحميل التقارير'],

            // Team / Users
            ['name' => 'إدارة المستخدمين', 'slug' => 'manage_users', 'category' => 'users', 'description' => 'دعوة وإدارة مستخدمي مساحة العمل'],

            // Billing & Subscription
            ['name' => 'إدارة الاشتراكات والفواتير', 'slug' => 'manage_billing', 'category' => 'billing', 'description' => 'عرض وإدارة الباقات والاشتراكات'],

            // Settings
            ['name' => 'إدارة الإعدادات', 'slug' => 'manage_settings', 'category' => 'settings', 'description' => 'تعديل إعدادات مساحة العمل'],

            // API Credentials
            ['name' => 'إدارة مفاتيح API', 'slug' => 'manage_api', 'category' => 'api', 'description' => 'إنشاء وإلغاء مفاتيح API الخاصة بمساحة العمل'],
        ];

        $createdPermissions = [];
        foreach ($permissions as $perm) {
            $createdPermissions[$perm['slug']] = Permission::updateOrCreate(
                ['slug' => $perm['slug']],
                $perm
            );
        }

        // 2. Create System Roles
        $roles = [
            [
                'name' => 'مدير النظام الفائق',
                'slug' => Role::SUPER_ADMIN,
                'description' => 'صلاحيات كاملة على مستوى المنصة وجميع المؤسسات',
                'is_system' => true,
                'permissions' => array_keys($createdPermissions), // All permissions
            ],
            [
                'name' => 'مالك مساحة العمل',
                'slug' => Role::TENANT_OWNER,
                'description' => 'صلاحيات كاملة لإدارة المؤسسة والمستخدمين والاشتراكات',
                'is_system' => true,
                'permissions' => array_keys($createdPermissions), // All permissions
            ],
            [
                'name' => 'مدير النظام للمؤسسة',
                'slug' => Role::TENANT_ADMIN,
                'description' => 'إدارة الكلمات المفتاحية والمصادر والمستخدمين والتنبيهات',
                'is_system' => true,
                'permissions' => [
                    'view_dashboard', 'manage_keywords', 'manage_sources', 'view_articles',
                    'manage_articles', 'manage_alerts', 'manage_reports', 'manage_users',
                    'manage_settings', 'manage_api',
                ],
            ],
            [
                'name' => 'محلل إعلامي',
                'slug' => Role::ANALYST,
                'description' => 'إدارة الكلمات المفتاحية والمقالات واستخراج التقارير',
                'is_system' => true,
                'permissions' => [
                    'view_dashboard', 'manage_keywords', 'view_articles',
                    'manage_articles', 'manage_alerts', 'manage_reports',
                ],
            ],
            [
                'name' => 'مستعرض',
                'slug' => Role::VIEWER,
                'description' => 'قراءة واستعراض لوحة التحكم والمقالات والتقارير فقط',
                'is_system' => true,
                'permissions' => [
                    'view_dashboard', 'view_articles',
                ],
            ],
        ];

        foreach ($roles as $roleData) {
            $permSlugs = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = Role::updateOrCreate(
                ['slug' => $roleData['slug'], 'tenant_id' => null],
                $roleData
            );

            $permissionIds = Permission::whereIn('slug', $permSlugs)->pluck('id');
            $role->permissions()->sync($permissionIds);
        }
    }
}
