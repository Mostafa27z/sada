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
                'description' => 'مثالية للشركات الناشئة والمؤسسات الصغيرة لمتابعة العلامة التجارية وحماية السمعة',
                'price' => 349.00,
                'currency' => 'SAR',
                'billing_interval' => 'monthly',
                'max_users' => 3,
                'max_keywords' => 10,
                'max_sources' => 30,
                'max_articles' => 3000,
                'max_api_requests' => 1000,
                'max_campaigns' => 10,
                'max_articles_per_campaign' => 100,
                'features' => [
                    'رصد المواقع الإخبارية ومنصات التواصل',
                    'تحليل المشاعر التلقائي بالذكاء الاصطناعي',
                    'تقارير أسبوعية وتنبيهات البريد الإلكتروني',
                    'حتى 3,000 منشور ومقال شهرياً',
                ],
                'has_news' => false,
                'is_active' => true,
            ],
            [
                'name' => 'الباقة الاحترافية',
                'slug' => 'pro',
                'description' => 'للمؤسسات والوكالات الإعلامية التي تتطلب تتبعاً شاملاً وتنبيهات ورادار أخبار فوري',
                'price' => 899.00,
                'currency' => 'SAR',
                'billing_interval' => 'monthly',
                'max_users' => 10,
                'max_keywords' => 30,
                'max_sources' => 100,
                'max_articles' => 12000,
                'max_api_requests' => 10000,
                'max_campaigns' => 30,
                'max_articles_per_campaign' => 400,
                'features' => [
                    'رصد شامل لجميع شبكات التواصل والمواقع',
                    'رادار الأخبار اليومي الحصري بالذكاء الاصطناعي',
                    'تنبيهات فورية عند رصد الأزمات',
                    'تصدير التقارير بصيغة PDF و CSV',
                    'حتى 12,000 منشور ومقال شهرياً',
                ],
                'has_news' => true,
                'is_active' => true,
            ],
            [
                'name' => 'باقة المؤسسات',
                'slug' => 'enterprise',
                'description' => 'حلول متقدمة وشاملة للجهات الحكومية والشركات الكبرى بحجم رصد واسع وإمكانيات مخصصة',
                'price' => 2499.00,
                'currency' => 'SAR',
                'billing_interval' => 'monthly',
                'max_users' => 25,
                'max_keywords' => 80,
                'max_sources' => 250,
                'max_articles' => 35000,
                'max_api_requests' => 50000,
                'max_campaigns' => 100,
                'max_articles_per_campaign' => 1000,
                'features' => [
                    'رادار الأخبار اليومي المتقدم مع اقتراحات تشغيلية',
                    'تغطية واسعة حتى 35,000 منشور ومقال شهرياً',
                    'وصول كامل لمفاتيح API والربط المخصص',
                    'مدير حساب خاص ودعم فني على مدار الساعة 24/7',
                    'تقارير مخصصة بالذكاء الاصطناعي',
                ],
                'has_news' => true,
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
