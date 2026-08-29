<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'الباقة الأساسية',
                'slug' => 'basic',
                'description' => 'مثالية للشركات الناشئة والمؤسسات الصغيرة لمتابعة العلامة التجارية',
                'price' => 299.00,
                'currency' => 'SAR',
                'billing_interval' => 'monthly',
                'max_users' => 5,
                'max_keywords' => 15,
                'max_sources' => 50,
                'max_articles' => 10000,
                'max_api_requests' => 1000,
                'features' => [
                    'رصد المواقع الإخبارية والمدونات',
                    'تقارير أسبوعية',
                    'تنبيهات البريد الإلكتروني',
                ],
                'is_active' => true,
            ],
            [
                'name' => 'الباقة الاحترافية',
                'slug' => 'pro',
                'description' => 'للمؤسسات والوكالات الإعلامية التي تتطلب تتبعاً شاملاً وتنبيهات فورية',
                'price' => 799.00,
                'currency' => 'SAR',
                'billing_interval' => 'monthly',
                'max_users' => 20,
                'max_keywords' => 50,
                'max_sources' => 200,
                'max_articles' => 50000,
                'max_api_requests' => 10000,
                'features' => [
                    'رصد شامل للأخبار والشبكات',
                    'تحليل المشاعر والذكاء الاصطناعي',
                    'تنبيهات فورية وحصلية',
                    'تصدير التقارير بصيغة PDF و CSV',
                ],
                'is_active' => true,
            ],
            [
                'name' => 'باقة المؤسسات',
                'slug' => 'enterprise',
                'description' => 'حلول مخصصة للجهات الحكومية والشركات الكبرى بحجم رصد غير محدود',
                'price' => 2499.00,
                'currency' => 'SAR',
                'billing_interval' => 'monthly',
                'max_users' => 100,
                'max_keywords' => 500,
                'max_sources' => 1000,
                'max_articles' => 500000,
                'max_api_requests' => 100000,
                'features' => [
                    'وصول كامل لمفاتيح API',
                    'مدير حساب خاص',
                    'دعم فني على مدار الساعة 24/7',
                    'تقارير مخصصة بالذكاء الاصطناعي',
                ],
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
