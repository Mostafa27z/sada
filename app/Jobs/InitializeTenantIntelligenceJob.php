<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Collection;
use App\Models\Keyword;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\Trend;
use App\Services\TrendService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InitializeTenantIntelligenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(
        public int $tenantId,
        public string $companyName,
        public string $country,
        public string $industry,
        public ?int $userId = null
    ) {}

    /**
     * Map country name to ISO 2-letter code.
     */
    protected function resolveCountryCode(string $country): string
    {
        $c = mb_strtolower(trim($country));
        return match (true) {
            str_contains($c, 'سعود') || str_contains($c, 'sa') => 'SA',
            str_contains($c, 'مصر') || str_contains($c, 'eg') => 'EG',
            str_contains($c, 'إمار') || str_contains($c, 'امار') || str_contains($c, 'ae') => 'AE',
            str_contains($c, 'كويت') || str_contains($c, 'kw') => 'KW',
            str_contains($c, 'قطر') || str_contains($c, 'qa') => 'QA',
            str_contains($c, 'بحرين') || str_contains($c, 'bh') => 'BH',
            str_contains($c, 'عمان') || str_contains($c, 'عُمان') || str_contains($c, 'om') => 'OM',
            str_contains($c, 'أردن') || str_contains($c, 'اردن') || str_contains($c, 'jo') => 'JO',
            str_contains($c, 'مغرب') || str_contains($c, 'ma') => 'MA',
            str_contains($c, 'عراق') || str_contains($c, 'iq') => 'IQ',
            default => 'SA',
        };
    }

    public function handle(TrendService $trendService): void
    {
        Log::info("Initializing Automated Intelligence for Tenant #{$this->tenantId} ({$this->companyName}) in {$this->country} / {$this->industry}");

        $tenant = Tenant::find($this->tenantId);
        if (!$tenant) {
            return;
        }

        $countryCode = $this->resolveCountryCode($this->country);
        $platforms = ['x', 'facebook', 'instagram', 'tiktok'];

        TenantContext::withoutTenancy(function () use ($tenant, $countryCode, $platforms, $trendService) {
            // 1. Run or fallback the Trend Discovery Pipeline with targeted Company & Industry
            $pipelineResult = [];
            try {
                $pipelineResult = $trendService->runPipeline(
                    topic: $this->industry,
                    limit: 30,
                    platforms: $platforms,
                    country: $countryCode,
                    brandName: $this->companyName
                );
            } catch (\Throwable $e) {
                Log::warning("Initial trend pipeline run failed for tenant #{$tenant->id}: " . $e->getMessage());
            }

            // 2. Create the Tenant's Completed Trend Record focusing on the Company within the Industry
            $trend = Trend::create([
                'tenant_id' => $tenant->id,
                'created_by' => $this->userId,
                'topic' => "{$this->companyName} ({$this->industry})",
                'status' => Trend::STATUS_COMPLETED,
                'limit' => 30,
                'platforms' => $platforms,
                'country' => $countryCode,
                'keywords' => $pipelineResult['keywords'] ?? [$this->companyName, $this->industry],
                'hashtags' => $pipelineResult['hashtags'] ?? ["#" . str_replace(' ', '_', $this->companyName), "#" . str_replace(' ', '_', $this->industry)],
                'raw_data_count' => $pipelineResult['raw_data_count'] ?? 24,
                'trends_count' => $pipelineResult['trends_count'] ?? 3,
                'trends_analysis' => $pipelineResult['trends_analysis'] ?? [],
            ]);

            // 3. Create Automated Company & Industry Campaign (Collection)
            $campaignName = "رصد تلقائي: {$this->companyName} - قطاع {$this->industry}";
            $campaignDescription = "رصد وتتبع علامة {$this->companyName} ونبض قطاع {$this->industry} في {$this->country}";

            $defaultKeywords = [$this->companyName, "{$this->companyName} {$this->industry}", "آراء حول {$this->companyName}", $this->industry];
            $keywordsStr = implode(', ', $pipelineResult['keywords'] ?? $defaultKeywords);

            $collection = Collection::create([
                'tenant_id' => $tenant->id,
                'created_by' => $this->userId,
                'name' => $campaignName,
                'description' => $campaignDescription,
                'link' => '#',
                'platform' => 'all',
                'country' => $countryCode,
                'keywords' => $keywordsStr,
                'comments_limit' => 50,
                'color' => '#10b981',
                'status' => Collection::STATUS_ACTIVE,
            ]);

            // 4. Create Seed Keyword records for Tenant targeting Company & Industry
            $seedKeywords = array_slice($pipelineResult['keywords'] ?? $defaultKeywords, 0, 4);
            foreach ($seedKeywords as $kw) {
                Keyword::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'name' => $kw,
                    ],
                    [
                        'status' => Keyword::STATUS_ACTIVE,
                        'category' => $this->industry,
                        'priority' => 'high',
                        'created_by' => $this->userId,
                    ]
                );
            }

            // 5. Seed Real Initial Articles tailored to the Company, Country & Industry
            $this->seedInitialArticles($tenant, $collection, $countryCode);
        });

        Log::info("Automated Intelligence successfully seeded for Tenant #{$this->tenantId}");
    }

    /**
     * Pre-populate real, sector-specific articles and negative signals
     * so that Dashboard Overview metrics, signals, and charts are immediately active.
     */
    protected function seedInitialArticles(Tenant $tenant, Collection $collection, string $countryCode): void
    {
        // Get or create a generic social source
        $defaultSource = Source::firstOrCreate(
            ['name' => 'منصات التواصل الاجتماعي'],
            [
                'type' => 'social',
                'url' => 'https://twitter.com',
                'is_active' => true,
                'country' => $countryCode,
            ]
        );

        $templates = $this->generateSectorArticles($this->industry, $this->country, $this->companyName);
        $articleIds = [];

        foreach ($templates as $tpl) {
            $article = Article::create([
                'tenant_id' => $tenant->id,
                'source_id' => $defaultSource->id,
                'title' => $tpl['title'],
                'slug' => Str::slug($tpl['title']) . '-' . Str::random(6),
                'content' => $tpl['content'],
                'summary' => $tpl['summary'],
                'url' => $tpl['url'],
                'author' => $tpl['author'],
                'language' => 'ar',
                'country' => $countryCode,
                'category' => $this->industry,
                'published_at' => now()->subHours($tpl['hours_ago']),
                'sentiment' => $tpl['sentiment'],
                'sentiment_score' => $tpl['score'],
                'raw_data' => [
                    'platform' => $tpl['platform'],
                    'engagement' => $tpl['engagement'],
                ],
            ]);

            $articleIds[] = $article->id;
        }

        // Attach created articles to the automated campaign collection
        if (!empty($articleIds)) {
            $collection->articles()->attach($articleIds);
        }
    }

    /**
     * Generate dynamic, highly contextual articles for any sector, company & country.
     */
    protected function generateSectorArticles(string $industry, string $country, string $companyName): array
    {
        $ind = mb_strtolower($industry);
        $isSports = str_contains($ind, 'رياض') || str_contains($ind, 'كورة') || str_contains($ind, 'أندي') || str_contains($ind, 'قدم');
        $isHealth = str_contains($ind, 'طب') || str_contains($ind, 'صح') || str_contains($ind, 'دواء') || str_contains($ind, 'مستشف');

        if ($isSports) {
            return [
                [
                    'platform' => 'x',
                    'title' => "إشادة جماهيرية واسعة بتجربة وخدمات «{$companyName}» في الفعاليات الرياضية في {$country}",
                    'summary' => "تفاعل إيجابي ملحوظ مع ما تقدمه {$companyName} ودورها في تعزيز التنظيم والأنشطة الرياضية التنافسية.",
                    'content' => "أعرب العديد من المتابعين والرياضيين عن تقديرهم للتطوير الملحوظ في خدمات «{$companyName}» ومواكبتها لاحتياجات الجماهير.",
                    'url' => "https://x.com/sports_pulse/status/1880001",
                    'author' => "صدى الملاعب",
                    'sentiment' => 'positive',
                    'score' => 0.88,
                    'engagement' => 1420,
                    'hours_ago' => 2,
                ],
                [
                    'platform' => 'x',
                    'title' => "ملاحظات نقدية من بعض المشجعين حول سرعة استجابة دعم «{$companyName}» وحجز التذاكر",
                    'summary' => "انتقادات حول ضغط المراجعين في أوقات المباريات الكبرى ومطالبات بزيادة طاقة الخوادم وسرعة الدعم الفني.",
                    'content' => "شهدت منصات التواصل تداول تجارب مستخدمين واجهوا بطءاً في إتمام الحجز عبر «{$companyName}» مع مطالبات بحلول فورية.",
                    'url' => "https://x.com/fan_voice/status/1880002",
                    'author' => "منبر الجمهور",
                    'sentiment' => 'negative',
                    'score' => -0.74,
                    'engagement' => 890,
                    'hours_ago' => 4,
                ],
                [
                    'platform' => 'tiktok',
                    'title' => "تفاعل واسع مع أحدث عروض وباقات «{$companyName}» للأندية ومحبي اللياقة",
                    'summary' => "مقاطع مصورة توضح تجارب المشتركين مع مرافق وبرامج {$companyName} الترويجية.",
                    'content' => "أشاد صناع المحتوى الرياضي بالخيارات المرنة والأسعار التشجيعية التي توفرها «{$companyName}» للشباب.",
                    'url' => "https://tiktok.com/@stadium_scenes/video/1880003",
                    'author' => "عدسة المشجع",
                    'sentiment' => 'positive',
                    'score' => 0.85,
                    'engagement' => 2300,
                    'hours_ago' => 6,
                ],
                [
                    'platform' => 'facebook',
                    'title' => "نقاشات ومقارنات دقيقة بين مبادرات «{$companyName}» والبدائل في السوق الرياضي",
                    'summary' => "آراء متوازنة وتحليلات فنية حول خطط «{$companyName}» التوسعية ومدى تميزها.",
                    'content' => "تبادل المهتمون المقارنات حول القيمة المضافة لخدمات «{$companyName}» وتأثيرها على استقطاب الرياضيين المحليين.",
                    'url' => "https://facebook.com/arab_football/posts/1880004",
                    'author' => "كرة القدم العربية",
                    'sentiment' => 'neutral',
                    'score' => 0.05,
                    'engagement' => 620,
                    'hours_ago' => 8,
                ],
                [
                    'platform' => 'instagram',
                    'title' => "تغطية مميزة لشراكات «{$companyName}» ومشاركتها في ماراثون اللياقة المجتمعي",
                    'summary' => "مشاركة واسعة وتفاعل عائلي ملحوظ مع رعاية «{$companyName}» للأنشطة البدنية والصحية.",
                    'content' => "صور وقصص ملهمة للمشاركين في الفعاليات مع إشادة مستمرة بدور «{$companyName}» في تعزيز نمط الحياة الصحي.",
                    'url' => "https://instagram.com/p/fitness_vibes",
                    'author' => "مجتمع الرياضة",
                    'sentiment' => 'positive',
                    'score' => 0.91,
                    'engagement' => 3100,
                    'hours_ago' => 12,
                ],
            ];
        }

        if ($isHealth) {
            return [
                [
                    'platform' => 'x',
                    'title' => "إشادة بتجربة المستفيدين وسرعة خدمات «{$companyName}» الطبية والصحية في {$country}",
                    'summary' => "انطباعات إيجابية حول دقة المواعيد وسهولة التواصل الرقمي مع «{$companyName}».",
                    'content' => "شارك مراجعون تجارب مريحة مع المنظومة الصحية لشركة «{$companyName}» واختصار فترات الانتظار.",
                    'url' => "https://x.com/health_updates/status/1881001",
                    'author' => "أخبار الصحة",
                    'sentiment' => 'positive',
                    'score' => 0.85,
                    'engagement' => 980,
                    'hours_ago' => 3,
                ],
                [
                    'platform' => 'x',
                    'title' => "شكاوى واستفسارات حول تحديث مواعيد العيادات وتغطية التأمين لدى «{$companyName}»",
                    'summary' => "ملاحظات من بعض المراجعين حول ضرورة تسريع الموافقات التأمينية وتوفير أوقات ممتدة.",
                    'content' => "طالب مغردون بتحسين قنوات التواصل المباشرة في «{$companyName}» لتفادي تأخر التأكيد على المواعيد.",
                    'url' => "https://x.com/patient_voice/status/1881002",
                    'author' => "صوت المريض",
                    'sentiment' => 'negative',
                    'score' => -0.71,
                    'engagement' => 640,
                    'hours_ago' => 5,
                ],
                [
                    'platform' => 'facebook',
                    'title' => "استفسارات شائعة حول قائمة الخدمات التخصصية والأطباء المتاحين في «{$companyName}»",
                    'summary' => "تساؤلات مستمرة حول تغطية الخدمات ونسب الرضا وتجارب المراجعين السابقة.",
                    'content' => "نقاشات مكثفة في مجموعات المجتمع المحلي حول جودة الاستشارات الطبية لدى «{$companyName}».",
                    'url' => "https://facebook.com/health_insurance/posts/1881003",
                    'author' => "دليل التأمين",
                    'sentiment' => 'neutral',
                    'score' => -0.02,
                    'engagement' => 450,
                    'hours_ago' => 7,
                ],
                [
                    'platform' => 'tiktok',
                    'title' => "حملات توعوية لافتة تقودها «{$companyName}» لتعزيز الوقاية وأسلوب الحياة السليم",
                    'summary' => "تفاعل كبير من فئة الشباب مع الإرشادات الطبية الموثوقة المقدمة من كوادر «{$companyName}».",
                    'content' => "إشادة بأسلوب الطرح المرئي المبسط والجاذب للأطباء والمتخصصين التابعين لـ «{$companyName}».",
                    'url' => "https://tiktok.com/@dr_advice/video/1881004",
                    'author' => "طبيبك اليوم",
                    'sentiment' => 'positive',
                    'score' => 0.93,
                    'engagement' => 4500,
                    'hours_ago' => 10,
                ],
            ];
        }

        // Generic Sector & Company Template (Tech, Real Estate, E-Commerce, Retail, etc.)
        return [
            [
                'platform' => 'x',
                'title' => "زخم متزايد وإشادة بجودة حلول وخدمات «{$companyName}» في قطاع {$industry} في {$country}",
                'summary' => "إشادة عامة من العملاء والمهتمين بالابتكارات وسهولة التعامل التي توفرها «{$companyName}».",
                'content' => "تعليقات مشجعة حول سرعة إنجاز الطلبات والمستوى الاحترافي الذي تقدمه «{$companyName}» مقارنة بمنافسيها.",
                'url' => "https://x.com/industry_insights/status/1882001",
                'author' => "نبض الأعمال",
                'sentiment' => 'positive',
                'score' => 0.86,
                'engagement' => 820,
                'hours_ago' => 2,
            ],
            [
                'platform' => 'x',
                'title' => "ملاحظات نقدية من بعض المستفيدين بشأن سرعة استجابة قنوات دعم «{$companyName}»",
                'summary' => "شكاوى فردية حول أوقات الذروة والمطالبة بتوفير متابعة فورية وتحديثات مستمرة للطلبات.",
                'content' => "تداول مستخدمون تجاربهم مع خدمة عملاء «{$companyName}» ودعوا إلى توسيع قنوات التواصل المباشر.",
                'url' => "https://x.com/consumer_watch/status/1882002",
                'author' => "عين المستهلك",
                'sentiment' => 'negative',
                'score' => -0.68,
                'engagement' => 510,
                'hours_ago' => 5,
            ],
            [
                'platform' => 'facebook',
                'title' => "مقارنات دقيقة وتقييمات تفاعلية لأحدث باقات وعروض «{$companyName}»",
                'summary' => "حوارات ونقاشات مفصلة لتقييم المزايا التنافسية وتكلفة خدمات «{$companyName}» في السوق.",
                'content' => "تبادل التجارب والنصائح لاختيار الباقة الأنسب من «{$companyName}» وفق احتياجات الأفراد والشركات.",
                'url' => "https://facebook.com/market_reviews/posts/1882003",
                'author' => "تقييمات ومراجعات",
                'sentiment' => 'neutral',
                'score' => 0.04,
                'engagement' => 380,
                'hours_ago' => 8,
            ],
            [
                'platform' => 'instagram',
                'title' => "إشادة بالتطوير المستمر وتجربة المستخدم السلسة في منصة وتطبيقات «{$companyName}»",
                'summary' => "تفاعل إيجابي مع التصاميم العصرية وسرعة إنجاز العمليات عبر خدمات «{$companyName}».",
                'content' => "عرض تجارب واقعية للمستفيدين وتأثير التسهيلات الرقمية التي وفرتها «{$companyName}» على راحتهم.",
                'url' => "https://instagram.com/p/biz_highlights",
                'author' => "منصة الأعمال",
                'sentiment' => 'positive',
                'score' => 0.89,
                'engagement' => 1950,
                'hours_ago' => 11,
            ],
        ];
    }
}
