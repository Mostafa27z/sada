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
            // ── Sidebar Links Permissions ─────────────────────────────────
            // 1. Dashboard
            ['name' => 'عرض لوحة التحكم الرئيسية', 'slug' => 'view_dashboard', 'category' => 'dashboard', 'description' => 'استعراض لوحة المؤشرات التنفيذية والإحصائيات التجميعية'],

            // 2. Industry News Radar
            ['name' => 'رادار أخبار القطاع', 'slug' => 'view_industry_news', 'category' => 'analytics', 'description' => 'استعراض رادار أخبار القطاع والقرارات الوزارية وتوصيات الذكاء الاصطناعي'],

            // 3. Campaigns
            ['name' => 'استعراض تحليل الحملات', 'slug' => 'view_campaigns', 'category' => 'analytics', 'description' => 'الاطلاع على تقارير وتحليلات أداء الحملات الإعلامية'],
            ['name' => 'إدارة وإنشاء الحملات', 'slug' => 'manage_campaigns', 'category' => 'analytics', 'description' => 'إنشاء وتعديل وتعيين المقالات للحملات'],

            // 4. Locations & Google Maps
            ['name' => 'استعراض فروع وخرائط Google', 'slug' => 'view_locations', 'category' => 'locations', 'description' => 'استعراض خريطة الفروع ومؤشرات الرضا وتقييمات العملاء'],
            ['name' => 'إدارة ومزامنة الفروع', 'slug' => 'manage_locations', 'category' => 'locations', 'description' => 'إضافة فروع جديدة، حذفها، ومزامنة وإعادة تحليل التقييمات'],

            // 5. Digital Reputation
            ['name' => 'رصد السمعة الرقمية', 'slug' => 'view_reputation', 'category' => 'analytics', 'description' => 'متابعة مؤشرات السمعة الرقمية وتطور الانطباع العام والأزمات'],

            // 6. Beneficiary Voice
            ['name' => 'صوت المستفيد', 'slug' => 'view_beneficiary_voice', 'category' => 'analytics', 'description' => 'الاطلاع على آراء وتطلعات المستفيدين والتحليل النوعي للردود'],

            // 7. Complaints
            ['name' => 'استعراض شكاوى العملاء', 'slug' => 'view_complaints', 'category' => 'complaints', 'description' => 'استعراض بلاغات وشكاوى العملاء ومؤشرات الأداء'],
            ['name' => 'معالجة وإدارة الشكاوى', 'slug' => 'manage_complaints', 'category' => 'complaints', 'description' => 'تحديث حالة الشكاوى وتدوين الملاحظات وتصدير رمز QR'],

            // 8. Smart Chat AI
            ['name' => 'المحادثة الذكية والتسويق', 'slug' => 'use_smart_chat', 'category' => 'ai', 'description' => 'التفاعل مع مستشار التسويق الذكي وغرف المحادثات الفورية'],

            // 9. Reports
            ['name' => 'استعراض التقارير', 'slug' => 'view_reports', 'category' => 'reports', 'description' => 'الاطلاع على التقارير المنشأة وقراءتها'],
            ['name' => 'إنشاء وإدارة التقارير', 'slug' => 'manage_reports', 'category' => 'reports', 'description' => 'إنشاء وتوليد تقارير مخصصة وتصديرها بصيغ PDF/Excel'],

            // 10. Services Management
            ['name' => 'إدارة الخدمات والكلمات والمصادر', 'slug' => 'manage_services', 'category' => 'management', 'description' => 'تهيئة وإدارة الكلمات المفتاحية ومصادر الرصد وقواعد التنبيه'],

            // 11. User Management & Custom Roles
            ['name' => 'إدارة المستخدمين والأدوار', 'slug' => 'manage_users', 'category' => 'management', 'description' => 'دعوة الموظفين وتعديل صلاحياتهم وإنشاء الأدوار المخصصة للمؤسسة'],

            // 12. Settings
            ['name' => 'إدارة إعدادات المؤسسة', 'slug' => 'manage_settings', 'category' => 'management', 'description' => 'تعديل هوية الحساب، معايير التحليل، والاشتراكات'],

            // ── Sub-level Operational Permissions ──────────────────────────
            ['name' => 'إدارة الكلمات المفتاحية', 'slug' => 'manage_keywords', 'category' => 'keywords', 'description' => 'إنشاء وتعديل وحذف وحالة الكلمات المفتاحية'],
            ['name' => 'إدارة المصادر', 'slug' => 'manage_sources', 'category' => 'sources', 'description' => 'إضافة وتعديل وإدارة المصادر'],
            ['name' => 'عرض المقالات والأخبار', 'slug' => 'view_articles', 'category' => 'articles', 'description' => 'استعراض وقراءة المقالات والأخبار المترصدة'],
            ['name' => 'إدارة المقالات والأخبار', 'slug' => 'manage_articles', 'category' => 'articles', 'description' => 'حذف وتعديل وحفظ المقالات'],
            ['name' => 'إدارة التنبيهات', 'slug' => 'manage_alerts', 'category' => 'alerts', 'description' => 'إعداد واستقبال وحل التنبيهات'],
            ['name' => 'إدارة الاشتراكات والفواتير', 'slug' => 'manage_billing', 'category' => 'billing', 'description' => 'عرض وإدارة الباقات والاشتراكات'],
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
                'description' => 'إدارة شاملة لجميع أقسام المنصة، المستخدمين، والأدوار المخصصة',
                'is_system' => true,
                'permissions' => [
                    'view_dashboard',
                    'view_industry_news',
                    'view_campaigns', 'manage_campaigns',
                    'view_locations', 'manage_locations',
                    'view_reputation',
                    'view_beneficiary_voice',
                    'view_complaints', 'manage_complaints',
                    'use_smart_chat',
                    'view_reports', 'manage_reports',
                    'manage_services',
                    'manage_users',
                    'manage_settings',
                    'manage_keywords', 'manage_sources', 'view_articles',
                    'manage_articles', 'manage_alerts', 'manage_api',
                ],
            ],
            [
                'name' => 'محلل إعلامي وتجربة عملاء',
                'slug' => Role::ANALYST,
                'description' => 'استعراض وتحليل المؤشرات والحملات، الفروع، السمعة، والتقارير',
                'is_system' => true,
                'permissions' => [
                    'view_dashboard',
                    'view_industry_news',
                    'view_campaigns', 'manage_campaigns',
                    'view_locations',
                    'view_reputation',
                    'view_beneficiary_voice',
                    'use_smart_chat',
                    'view_reports', 'manage_reports',
                    'view_articles', 'manage_articles',
                    'manage_keywords', 'manage_alerts',
                ],
            ],
            [
                'name' => 'مستعرض',
                'slug' => Role::VIEWER,
                'description' => 'قراءة واستعراض مؤشرات لوحة التحكم والفروع والتقارير فقط',
                'is_system' => true,
                'permissions' => [
                    'view_dashboard',
                    'view_industry_news',
                    'view_locations',
                    'view_reputation',
                    'view_beneficiary_voice',
                    'view_reports',
                    'view_articles',
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
