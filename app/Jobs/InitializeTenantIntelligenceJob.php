<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Collection;
use App\Models\Keyword;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\Trend;
use App\Services\AiScraperService;
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

    public int $timeout = 600;

    public function __construct(
        public int $tenantId,
        public string $companyName,
        public string $country,
        public ?string $city = null,
        public string $industry = '',
        public ?string $companyDescription = null,
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

    public function handle(TrendService $trendService, AiScraperService $scraper): void
    {
        Log::info("Initializing Automated Intelligence for Tenant #{$this->tenantId} ({$this->companyName}) in {$this->country} / {$this->industry}");

        $tenant = Tenant::find($this->tenantId);
        if (!$tenant) {
            return;
        }

        $countryCode = $this->resolveCountryCode($this->country);
        $platforms = ['x', 'facebook', 'instagram', 'tiktok'];

        TenantContext::withoutTenancy(function () use ($tenant, $countryCode, $platforms, $trendService, $scraper) {
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

            // 3. Create TWO Separate Campaigns (Collections): 1) Brand Specific, 2) General Sector
            $cleanName = trim(preg_replace('/^(مدرسة|شركة|مؤسسة|سلسلة|مطعم|مستشفى|مركز)\s+/u', '', $this->companyName));
            $shortName = $cleanName ?: $this->companyName;

            // Brand Specific Keywords
            $brandKeywords = array_filter([
                $this->companyName,
                $shortName,
                $this->city ? "{$this->companyName} {$this->city}" : null,
                $this->city ? "{$shortName} {$this->city}" : null,
                "#" . str_replace(' ', '_', $this->companyName),
                "#" . str_replace(' ', '_', $shortName),
                $this->city ? "#" . str_replace(' ', '_', "{$shortName}_{$this->city}") : null,
                "آراء حول {$this->companyName}",
                "تجربة {$shortName}",
            ]);
            $brandKeywords = array_values(array_unique($brandKeywords));

            // Sector General Keywords
            $sectorKeywords = $this->generateSectorKeywords($this->industry, $this->country);

            // Campaign 1: Brand Collection (Priority #1)
            $brandCampaignName = "رصد خاص: {$this->companyName}";
            if ($this->city) {
                $brandCampaignName .= " ({$this->city})";
            }
            $brandCollection = Collection::create([
                'tenant_id' => $tenant->id,
                'created_by' => $this->userId,
                'name' => $brandCampaignName,
                'description' => "رصد وتتبع علامة {$this->companyName} والوسوم والآراء المباشرة الموجهة لها في {$this->country}",
                'link' => '#',
                'platform' => 'all',
                'country' => $countryCode,
                'keywords' => implode(', ', $brandKeywords),
                'comments_limit' => 50,
                'color' => '#10b981',
                'status' => Collection::STATUS_ACTIVE,
            ]);

            // Campaign 2: Sector Collection (Priority #2)
            $sectorCampaignName = "تريندات قطاع {$this->industry} في {$this->country}";
            $sectorCollection = Collection::create([
                'tenant_id' => $tenant->id,
                'created_by' => $this->userId,
                'name' => $sectorCampaignName,
                'description' => "رصد ونبض القرارات والأخبار الشائعة والتريندات العامة لقطاع {$this->industry} في {$this->country}",
                'link' => '#',
                'platform' => 'all',
                'country' => $countryCode,
                'keywords' => implode(', ', $sectorKeywords),
                'comments_limit' => 50,
                'color' => '#6366f1',
                'status' => Collection::STATUS_ACTIVE,
            ]);

            // 4. Create Seed Keyword records: High priority for Brand, Medium priority for Sector
            foreach (array_slice($brandKeywords, 0, 6) as $kw) {
                Keyword::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $kw],
                    ['status' => Keyword::STATUS_ACTIVE, 'category' => 'علامة تجارية', 'priority' => 'high', 'created_by' => $this->userId]
                );
            }
            foreach (array_slice($sectorKeywords, 0, 5) as $kw) {
                Keyword::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $kw],
                    ['status' => Keyword::STATUS_ACTIVE, 'category' => $this->industry, 'priority' => 'medium', 'created_by' => $this->userId]
                );
            }

            // 5. Scrape REAL posts and populate both collections
            $this->scrapeAndSaveRealArticles($tenant, $brandCollection, $sectorCollection, $countryCode, $scraper);
        });
        
        if ($tenant->plan && $tenant->plan->has_news) {
            try {
                \App\Jobs\FetchTenantIndustryNewsJob::dispatch($this->tenantId);
            } catch (\Throwable $e) {
                Log::warning("Failed to dispatch FetchTenantIndustryNewsJob for tenant #{$this->tenantId}: " . $e->getMessage());
            }
        }

        Log::info("Automated Intelligence successfully seeded for Tenant #{$this->tenantId}");
    }

    /**
     * Fetch REAL social media posts about the company via AiScraperService (Apify + Gemini).
     * Falls back to static seed templates only if scraping returns nothing.
     */
    protected function scrapeAndSaveRealArticles(
        Tenant $tenant,
        Collection $brandCollection,
        Collection $sectorCollection,
        string $countryCode,
        AiScraperService $scraper
    ): void {
        // ── Keywords ذكية: اسم الشركة + المدينة + القطاع + الوصف ──────────
        $locationCtx = $this->city
            ? "{$this->companyName} {$this->city}"
            : $this->companyName;

        $keywords = array_filter([
            $locationCtx,
            "{$this->companyName} {$this->industry}",
            "آراء حول {$this->companyName}",
            "تجربة {$this->companyName}",
            "#" . str_replace(' ', '_', $this->companyName),
            $this->city ? "{$this->city} {$this->industry}" : $this->industry,
            "التعليم في {$this->country}",
        ]);
        $keywords = array_values(array_unique(array_filter($keywords)));

        Log::info("Starting real scrape for tenant #{$tenant->id} with keywords: " . implode(', ', $keywords));

        $result = [];
        try {
            $result = $scraper->scrapeByKeywords(
                keywords: $keywords,
                platforms: ['x', 'facebook', 'instagram', 'tiktok'],
                country: $countryCode,
                limit: 40,
            );
        } catch (\Throwable $e) {
            Log::warning("Real scraping failed for tenant #{$tenant->id}: " . $e->getMessage());
        }

        // جمع كل البوستات الحقيقية من كل البلاتفورمز
        $allPosts = [];
        if (!empty($result['posts_by_platform'])) {
            foreach ($result['posts_by_platform'] as $platform => $posts) {
                foreach ($posts as $post) {
                    $post['_platform'] = $platform;
                    $allPosts[] = $post;
                }
            }
        }

        // لو ما فيش بيانات حقيقية → fallback للـ seed الوهمي
        if (empty($allPosts)) {
            Log::info("No real posts found for tenant #{$tenant->id}, falling back to static seed.");
            $this->seedInitialArticles($tenant, $brandCollection, $sectorCollection, $countryCode);
            return;
        }

        $defaultSource = Source::firstOrCreate(
            ['name' => 'منصات التواصل الاجتماعي'],
            [
                'type'      => 'social',
                'url'       => 'https://twitter.com',
                'is_active' => true,
                'country'   => $countryCode,
            ]
        );

        $articleIds = [];

        foreach (array_slice($allPosts, 0, 40) as $post) {
            $platform  = $post['_platform'] ?? $post['platform'] ?? 'x';
            $text      = $post['text'] ?? $post['content'] ?? $post['caption'] ?? '';
            $postUrl   = $post['url'] ?? $post['post_url'] ?? $post['link'] ?? '#';
            $author    = $post['author'] ?? $post['username'] ?? $post['author_name'] ?? 'مجهول';
            $sentiment = $post['sentiment'] ?? 'neutral';
            $score     = (float)($post['sentiment_score'] ?? 0.0);
            $timestamp = $post['timestamp'] ?? $post['created_at'] ?? null;
            if ($timestamp) {
                if (is_numeric($timestamp)) {
                    $sec = (int)$timestamp;
                    if ($sec > 9999999999) {
                        $sec = (int)($sec / 1000);
                    }
                    $publishedAt = $sec > 946684800 ? \Carbon\Carbon::createFromTimestamp($sec) : now()->subHours(rand(1, 48));
                } else {
                    try {
                        $parsed = \Carbon\Carbon::parse($timestamp);
                        $publishedAt = $parsed->year >= 2000 ? $parsed : now()->subHours(rand(1, 48));
                    } catch (\Throwable) {
                        $publishedAt = now()->subHours(rand(1, 48));
                    }
                }
            } else {
                $publishedAt = now()->subHours(rand(1, 48));
            }

            if (empty($text)) continue;

            $article = Article::create([
                'tenant_id'       => $tenant->id,
                'source_id'       => $defaultSource->id,
                'title'           => mb_substr($text, 0, 120),
                'slug'            => Str::slug(mb_substr($text, 0, 60)) . '-' . Str::random(6),
                'content'         => $text,
                'summary'         => mb_substr($text, 0, 200),
                'url'             => $postUrl,
                'author'          => $author,
                'language'        => 'ar',
                'country'         => $countryCode,
                'category'        => $this->industry,
                'published_at'    => $publishedAt,
                'sentiment'       => $sentiment,
                'sentiment_score' => $score,
                'raw_data'        => [
                    'platform'   => $platform,
                    'engagement' => $post['likes_count'] ?? $post['likes'] ?? $post['engagement'] ?? 0,
                    'source'     => 'auto_intelligence',
                ],
            ]);

            $articleIds[] = $article->id;
        }

        if (!empty($articleIds)) {
            $brandCollection->articles()->attach($articleIds);
            $sectorCollection->articles()->attach($articleIds);
        }

        Log::info("Saved " . count($articleIds) . " REAL articles for tenant #{$tenant->id}");
    }

    /**
     * Pre-populate static, sector-specific articles (fallback only).
     */
    protected function seedInitialArticles(
        Tenant $tenant,
        Collection $brandCollection,
        Collection $sectorCollection,
        string $countryCode
    ): void {
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

        if (!empty($articleIds)) {
            $brandCollection->articles()->attach($articleIds);
            $sectorCollection->articles()->attach($articleIds);
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

    /**
     * Generate sector-wide macro keywords for general sector monitoring.
     */
    protected function generateSectorKeywords(string $industry, string $country): array
    {
        $ind = mb_strtolower($industry);

        if (str_contains($ind, 'تعليم') || str_contains($ind, 'جامع') || str_contains($ind, 'مدرس')) {
            return [
                "التعليم في {$country}",
                "قرارات وزير التعليم",
                "أخبار المدارس والجامعات",
                "تريندات التعليم في {$country}",
                "تطوير التعليم والامتحانات",
            ];
        }

        if (str_contains($ind, 'طب') || str_contains($ind, 'صح') || str_contains($ind, 'مستشف')) {
            return [
                "القطاع الصحي في {$country}",
                "قرارات وزارة الصحة",
                "تريندات الصحة والرعاية الطبية",
                "أخبار المستشفيات في {$country}",
                "الخدمات الطبية والعلاج",
            ];
        }

        if (str_contains($ind, 'أغذي') || str_contains($ind, 'طعام') || str_contains($ind, 'دواجن') || str_contains($ind, 'مطاعم') || str_contains($ind, 'مجمدات')) {
            return [
                "قطاع الأغذية والمطاعم في {$country}",
                "أسعار المنتجات الغذائية والدواجن",
                "تريندات الأغذية والمشروبات",
                "سلامة الغذاء والجودة",
                "أخبار شركات الأغذية في {$country}",
            ];
        }

        if (str_contains($ind, 'رياض') || str_contains($ind, 'أندي') || str_contains($ind, 'لياق')) {
            return [
                "الرياضة والأندية في {$country}",
                "تريندات الرياضة والفعاليات",
                "أخبار الأندية الرياضية في {$country}",
                "أنشطة اللياقة البدنية",
                "بطولات وفعاليات {$country}",
            ];
        }

        return [
            "قطاع {$industry} في {$country}",
            "تريندات {$industry}",
            "أخبار ومستجدات {$industry} في {$country}",
            "سوق {$industry} في {$country}",
        ];
    }
}
