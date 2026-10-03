<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantIndustryNews;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IndustryNewsService
{
    public function __construct(
        protected GeminiAnalyticsService $geminiService
    ) {}

    public function getGeminiService(): GeminiAnalyticsService
    {
        return $this->geminiService;
    }

    /**
     * Resolve the primary industry and country for a tenant.
     */
    public function resolveTenantSector(Tenant $tenant): array
    {
        $settings = $tenant->settings ?? [];
        $industry = $settings['industry'] ?? null;

        if (empty($industry)) {
            // Check articles category if available
            $firstCategory = DB::table('articles')
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->value('category');
            $industry = $firstCategory;
        }

        if (empty($industry)) {
            $name = mb_strtolower($tenant->name);
            if (str_contains($name, 'تقني') || str_contains($name, 'برمج') || str_contains($name, 'حلول') || str_contains($name, 'سدايا')) {
                $industry = 'تقنية المعلومات والتحول الرقمي';
            } elseif (str_contains($name, 'مستشف') || str_contains($name, 'صح') || str_contains($name, 'طب') || str_contains($name, 'علاج')) {
                $industry = 'الرعاية الصحية والمستشفيات';
            } elseif (str_contains($name, 'عقار') || str_contains($name, 'بناء') || str_contains($name, 'إعمار')) {
                $industry = 'العقارات والتطوير العقاري';
            } elseif (str_contains($name, 'تعليم') || str_contains($name, 'أكاديم') || str_contains($name, 'مدارس')) {
                $industry = 'التعليم والتدريب';
            } else {
                $industry = 'الرعاية الصحية والمستشفيات';
            }
        }

        $country = $settings['country'] ?? 'المملكة العربية السعودية';

        return [
            'industry' => trim($industry),
            'country' => trim($country),
            'company_name' => $tenant->name,
        ];
    }

    /**
     * Build search queries for Google News RSS based on the tenant's industry and country.
     */
    public function buildSearchQueries(string $industry, string $country = 'السعودية'): array
    {
        $lower = mb_strtolower($industry);
        $queries = [];

        if (str_contains($lower, 'صح') || str_contains($lower, 'مستشف') || str_contains($lower, 'طب') || str_contains($lower, 'علاج') || str_contains($lower, 'عياد')) {
            $queries[] = '("وزارة الصحة" OR "الرعاية الصحية" OR "مستشفيات" OR "الضمان الصحي") السعودية';
            $queries[] = '("هيئة الغذاء والدواء" OR "التجمع الصحي" OR "التحول الصحي" OR "وزير الصحة") السعودية';
        } elseif (str_contains($lower, 'تقني') || str_contains($lower, 'برمج') || str_contains($lower, 'ذكاء') || str_contains($lower, 'حاسب')) {
            $queries[] = '("وزارة الاتصالات" OR "هيئة الاتصالات" OR "الذكاء الاصطناعي" OR "سدايا" OR "التحول الرقمي") السعودية';
            $queries[] = '("شركات التقنية" OR "الاستثمار التقني" OR "الأمن السيبراني" OR "فينتك") السعودية';
        } elseif (str_contains($lower, 'عقار') || str_contains($lower, 'بناء') || str_contains($lower, 'تطوير عقار') || str_contains($lower, 'مقاول')) {
            $queries[] = '("الهيئة العامة للعقار" OR "وزارة البلديات والإسكان" OR "التطوير العقاري" OR "مزادات عقارية") السعودية';
            $queries[] = '("المشاريع العقارية" OR "صندوق الاستثمارات العقارية" OR "ضواحي سكنية") السعودية';
        } elseif (str_contains($lower, 'تعليم') || str_contains($lower, 'مدرس') || str_contains($lower, 'جامع') || str_contains($lower, 'تدريب')) {
            $queries[] = '("وزارة التعليم" OR "التعليم العالي" OR "الجامعات السعودية" OR "هيئة تقويم التعليم") السعودية';
        } elseif (str_contains($lower, 'تجار') || str_contains($lower, 'تجزئ') || str_contains($lower, 'تسوق') || str_contains($lower, 'متجر')) {
            $queries[] = '("وزارة التجارة" OR "التجارة الإلكترونية" OR "سلاسل الإمداد" OR "حماية المستهلك") السعودية';
        } else {
            // General business / specialized sector
            $queries[] = "(\"{$industry}\" OR \"قطاع {$industry}\") (\"وزارة\" OR \"هيئة\" OR \"قرارات\" OR \"استثمار\") السعودية";
            $queries[] = "(\"{$industry}\" OR \"سوق {$industry}\") (\"أنظمة\" OR \"لوائح\" OR \"مؤتمر\" OR \"شراكة\") السعودية";
        }

        return $queries;
    }

    /**
     * Fetch fresh candidate news items from Google News RSS feeds.
     */
    public function fetchCandidateNews(string $industry, string $country = 'السعودية', int $maxCandidates = 40): array
    {
        $queries = $this->buildSearchQueries($industry, $country);
        $candidates = [];
        $seenTitles = [];

        foreach ($queries as $query) {
            $url = 'https://news.google.com/rss/search?q=' . urlencode($query) . '&hl=ar&gl=SA&ceid=SA:ar';

            try {
                $response = Http::withOptions([
                    'verify' => false,
                    'timeout' => 15,
                    'follow_redirects' => true,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                        'Accept-Language' => 'ar,en-US;q=0.9,en;q=0.8',
                    ]
                ])->get($url);

                if ($response->successful()) {
                    $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

                    if ($xml && isset($xml->channel->item)) {
                        foreach ($xml->channel->item as $item) {
                            $title = trim((string)$item->title);
                            $cleanTitleKey = mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $title), 0, 40);

                            if (isset($seenTitles[$cleanTitleKey])) {
                                continue;
                            }
                            $seenTitles[$cleanTitleKey] = true;

                            $link = trim((string)$item->link);
                            $pubDateStr = (string)$item->pubDate;
                            $publishedAt = $pubDateStr ? Carbon::parse($pubDateStr)->toDateTimeString() : Carbon::now('Asia/Riyadh')->toDateTimeString();
                            $sourceName = trim((string)$item->source) ?: 'وسائل إعلام سعودية';
                            $desc = strip_tags(html_entity_decode((string)$item->description));

                            $candidates[] = [
                                'title' => $title,
                                'source_name' => $sourceName,
                                'source_url' => $link,
                                'published_at' => $publishedAt,
                                'summary' => mb_substr($desc, 0, 300),
                            ];

                            if (count($candidates) >= $maxCandidates) {
                                break 2;
                            }
                        }
                    }
                } else {
                    Log::warning("IndustryNewsService: RSS request returned status {$response->status()} for query [{$query}]");
                }
            } catch (\Throwable $e) {
                Log::warning("IndustryNewsService: failed to fetch RSS for query [{$query}]: " . $e->getMessage());
            }
        }

        return $candidates;
    }

    /**
     * Fallback: Directly synthesize the top macro industry developments, regulatory changes, and actionable suggestions
     * via Gemini AI when RSS feeds are unreachable due to server network restrictions.
     */
    public function generateDirectSectorNewsViaGemini(Tenant $tenant, int $targetCount = 10): array
    {
        $sector = $this->resolveTenantSector($tenant);
        $industry = $sector['industry'];
        $country = $sector['country'];
        $companyName = $sector['company_name'];
        $today = Carbon::now('Asia/Riyadh')->toDateString();

        $prompt = "أنت كبير المستشارين الاستراتيجيين ورئيس الاتصال المؤسسي لمنشأة '{$companyName}' المتخصصة في قطاع '{$industry}' في دولة '{$country}'.

المطلوب بدقة:
بصفتك خبيراً بالبيئة التشريعية والتنظيمية وبيئة الأعمال في المملكة العربية السعودية:
قم برصد وصياغة أهم {$targetCount} أحداث وقرارات وتطورات قطاعية كلية (Macro Industry Developments) حقيقية ومؤثرة في قطاع '{$industry}' حالياً.

شروط وقواعد حتمية:
1. التركيز على البيئة الكلية للقطاع: قرارات وتعيينات وزارية، مبادرات رؤية 2030، أنظمة ولوائح وتراخيص جديدة، استثمارات واندماجات، مؤتمرات وملتقيات كبرى، أو تحولات تقنية قطاعية.
2. استبعاد تام لأي شكاوى أو تجارب شخصية أو مراجعات فردية لعملاء/مرضى.
3. لكل حدث، حدد درجة الأهمية (1.0 إلى 10.0)، تصنيف الخبر، والمصدر الرسمي المرجعي (مثل واس، وزارة الصحة، وزارة التجارة، أرقام).
4. صياغة 3 إجراءات عملية دقيقة لكل خبر:
   - `social_post`: منشور احترافي متكامل جاهز للنشر على LinkedIn و X (الخطاف، النص الكامل بالعربية، الهاشتاقات).
   - `operational_action`: إجراء تنظيمي/إداري داخلي محدد للشركة مع الإدارة المعنية والإلحاح.
   - `marketing_opportunity`: فكرة حملة تسويقية أو ترويجية أو حزمة خدمات للاستفادة من الخبر.

يجب أن يكون الرد بتنسيق JSON حصراً بالشكل التالي:
{
  \"selected_news\": [
    {
      \"rank\": 1,
      \"title\": \"عنوان الحدث أو القرار الرسمي\",
      \"source_name\": \"المصدر الرسمي (واس / الوزارة المعنية)\",
      \"source_url\": \"https://spa.gov.sa\",
      \"published_at\": \"{$today}\",
      \"category\": \"قرارات وتشريعات وزارية\",
      \"importance_score\": 9.50,
      \"why_it_matters\": \"الأهمية الاستراتيجية للحدث بالنسبة للمنشأة...\",
      \"suggested_actions\": {
        \"social_post\": {
          \"recommended_platform\": [\"linkedin\", \"x\"],
          \"angle\": \"زاوية التناول\",
          \"hook\": \"الخطاف الجذاب...\",
          \"body\": \"النص الكامل باللغة العربية جاهز للنشر مباشرة...\",
          \"hashtags\": [\"#هاشتاق1\", \"#هاشتاق2\"]
        },
        \"operational_action\": {
          \"department\": \"الإدارة المعنية\",
          \"urgency\": \"high / medium / low\",
          \"instruction\": \"الإجراء الداخلي المطلوب...\"
        },
        \"marketing_opportunity\": {
          \"campaign_type\": \"نوع المبادرة\",
          \"concept\": \"فكرة الحملة أو المبادرة...\"
        }
      }
    }
  ]
}";

        $messages = [
            ['role' => 'system', 'content' => 'أنت مستشار استراتيجي ورئيس اتصالات مؤسسية لمنشآت الأعمال. تصدر تحليلات قطاعية ومقترحات تسويقية وتنظيمية دقيقة بتنسيق JSON حصراً.'],
            ['role' => 'user', 'content' => $prompt]
        ];

        try {
            $reflection = new \ReflectionClass($this->geminiService);
            $method = $reflection->getMethod('callGemini');
            $method->setAccessible(true);
            $rawJson = $method->invoke($this->geminiService, $messages, 120);

            if ($rawJson) {
                $parsed = json_decode($rawJson, true);
                if (isset($parsed['selected_news']) && is_array($parsed['selected_news'])) {
                    return array_slice($parsed['selected_news'], 0, $targetCount);
                }
            }
        } catch (\Throwable $e) {
            Log::error("IndustryNewsService: Direct Gemini sector news generation failed for tenant #{$tenant->id}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Analyze candidates with Gemini to filter macro sector news, select top 10, and generate 3 actionable proposals per news.
     */
    public function analyzeAndCurateTopNews(Tenant $tenant, array $candidates, int $targetCount = 10): array
    {
        $sector = $this->resolveTenantSector($tenant);
        $industry = $sector['industry'];
        $country = $sector['country'];
        $companyName = $sector['company_name'];

        if (empty($candidates)) {
            return [];
        }

        $candidatesJson = json_encode(array_slice($candidates, 0, 35), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = "أنت كبير المستشارين الاستراتيجيين ورئيس الاتصال المؤسسي لمنشأة '{$companyName}' المتخصصة في قطاع '{$industry}' في دولة '{$country}'.

قائمة الأخبار المرشحة التي تم رصدها خلال الـ 24 ساعة الماضية:
{$candidatesJson}

مهمتك الصارمة والدقيقة:
1. قاعدة الفلترة والاستبعاد الصارمة (Strict Macro-Only Gatekeeper):
   - يجب تضمين الأخبار التي تخص البيئة الكلية للقطاع (PESTEL Macro Environment) فقط:
     * قرارات وتعيينات وزارية، أوامر ملكية، وتغييرات قيادية حكومية (مثال: تعيين وزير جديد، تعيين وكلاء أو رؤساء هيئات تنظيمية).
     * قوانين ولوائح جديدة، تعاميم إلزامية، شروط تراخيص، أو سياسات تأمينية وتنظيمية.
     * استثمارات ضخمة، اندماجات واستحواذات، افتتاح مشاريع أو مستشفيات كبرى، أو ميزانيات مخصصة للقطاع.
     * مؤتمرات ومعارض وملتقيات قطاعية كبرى (مثل ملتقى الصحة العالمي، مؤتمرات الابتكار الطبي، إلخ).
     * ابتكارات وتحولات تقنية كبرى تؤثر على القطاع ككل.
   - استبعاد تام وحاسم لـ:
     * أي شكاوى أو تجارب فردية لمرضى أو عملاء عن المستشفيات أو الأطباء (مريض يشتكي أو يمدح مستشفى أو طبيب لا يعتبر خبراً قطاعياً!).
     * الحوادث الفردية، قضايا الأفراد، الشائعات، أو المناكفات على السوشيال ميديا.
     * الأخبار العامة التي لا تمس قطاع '{$industry}'.

2. اختيار وترتيب أهم {$targetCount} أخبار (Top {$targetCount} Most Important News):
   - اختر أفضل {$targetCount} أخبار (أو كل الأخبار المتوافقة إذا كان العدد أقل) ورتبها تنازلياً حسب درجة التأثير الاستراتيجي على المنشأة (من 1 إلى {$targetCount}).
   - حدد درجة الأهمية `importance_score` برقم عشري بين 7.00 و 10.00.
   - اكتب سبباً مقنعاً لـ `why_it_matters` يوضح كيف ينعكس هذا الخبر على خطط '{$companyName}'.

3. صياغة 3 إجراءات عملية ومقترحات ذكية لكل خبر (Actionable Proposals):
   أ. `social_post`: منشور احترافي كامل وجاهز للنشر مباشرة على منصات التواصل (LinkedIn و X) متضمناً زاوية التناول (تهنئة، توعية باللائحة، إبراز ريادة المنشأة)، الخطاف الجذاب، النص الكامل بأسلوب احترافي عربي رفيع، والهاشتاقات المناسبة.
   ب. `operational_action`: إجراء إداري/تنظيمي داخلي (امتثال، مراجعة سياسات، تدريب كوادر) مع تحديد الإدارة المعنية ومستوى الإلحاح (high, medium, low).
   ج. `marketing_opportunity`: فرصة تسويقية أو تجارية (حملة موازية، باقة خدمات، مبادرة شراكة) للاستفادة من الزخم الإعلامي للخبر.

يجب أن يكون الرد بتنسيق JSON حصراً كالتالي:
{
  \"selected_news\": [
    {
      \"rank\": 1,
      \"title\": \"عنوان الخبر الرسمي النظيف\",
      \"source_name\": \"اسم المصدر الإعلامي\",
      \"source_url\": \"رابط الخبر الأصلي إن توفر\",
      \"published_at\": \"وقت وتاريخ النشر\",
      \"category\": \"تصنيف الخبر (قرارات وتشريعات وزارية / استثمارات وشراكات / مؤتمرات وأحداث كبرى / تكنولوجيا وابتكار)\",
      \"importance_score\": 9.50,
      \"why_it_matters\": \"السبب الاستراتيجي لأهمية الخبر بالنسبة للمنشأة...\",
      \"suggested_actions\": {
        \"social_post\": {
          \"recommended_platform\": [\"linkedin\", \"x\"],
          \"angle\": \"زاوية المنشور (مثال: تهنئة رسمية ومواءمة استراتيجية)\",
          \"hook\": \"عبارة افتتاحية قوية وجذابة تجذب القارئ...\",
          \"body\": \"النص الكامل المكتمل للمنشور باللغة العربية جاهز للنشر فوراً دون تعديل...\",
          \"hashtags\": [\"#هاشتاق1\", \"#هاشتاق2\", \"#هاشتاق3\"]
        },
        \"operational_action\": {
          \"department\": \"الإدارة المعنية (مثل: إدارة الجودة والامتثال / الشؤون القانونية)\",
          \"urgency\": \"high / medium / low\",
          \"instruction\": \"الإجراء العملي المحدد والواضح المطلوب من الفريق الداخلي...\"
        },
        \"marketing_opportunity\": {
          \"campaign_type\": \"نوع المبادرة (مثل: حملة توعوية / باقة فحص / ندوة رقمية)\",
          \"concept\": \"فكرة الحملة أو المبادرة التسويقية لركوب موجة هذا الخبر...\"
        }
      }
    }
  ]
}";

        $messages = [
            ['role' => 'system', 'content' => 'أنت مستشار استراتيجي ورئيس اتصالات مؤسسية لمنشآت الأعمال. تصدر تحليلات قطاعية ومقترحات تسويقية وتنظيمية دقيقة بتنسيق JSON حصراً.'],
            ['role' => 'user', 'content' => $prompt]
        ];

        try {
            $reflection = new \ReflectionClass($this->geminiService);
            $method = $reflection->getMethod('callGemini');
            $method->setAccessible(true);
            $rawJson = $method->invoke($this->geminiService, $messages, 120);

            if ($rawJson) {
                $parsed = json_decode($rawJson, true);
                if (isset($parsed['selected_news']) && is_array($parsed['selected_news'])) {
                    return array_slice($parsed['selected_news'], 0, $targetCount);
                }
            }
        } catch (\Throwable $e) {
            Log::error("IndustryNewsService: Gemini curation failed for tenant #{$tenant->id}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Run the full daily news process for a tenant and persist top 10 items.
     */
    public function fetchAndProcessDailyNewsForTenant(Tenant $tenant, ?string $targetDate = null, ?\Closure $stepLogger = null): array
    {
        $log = $stepLogger ?? fn(string $msg) => null;
        $batchDate = $targetDate ?: Carbon::now('Asia/Riyadh')->toDateString();
        $sector = $this->resolveTenantSector($tenant);

        Log::info("IndustryNewsService: Processing daily industry news for tenant #{$tenant->id} ({$tenant->name}) for date {$batchDate} in sector [{$sector['industry']}]");

        // 1. Fetch live RSS candidates
        $log("  [1/3] جلب الأخبار المرشحة عبر قنوات RSS للقطاع ({$sector['industry']})...");
        $candidates = $this->fetchCandidateNews($sector['industry'], $sector['country'], 40);

        $curatedNews = [];
        if (!empty($candidates)) {
            $log("  --> تم رصد " . count($candidates) . " خبراً عبر RSS. جاري التصفية وانتقاء أهم 10 أخبار بالذكاء الاصطناعي...");
            $curatedNews = $this->analyzeAndCurateTopNews($tenant, $candidates, 10);
        }

        // If RSS was unreachable or returned 0 news, or Gemini returned empty, use Direct Sector Intelligence
        if (empty($curatedNews)) {
            $log("  --> تنبيه: تعذر الوصول للأخبار عبر RSS من السيرفر. الانتقال التلقائي للذكاء الاصطناعي المباشر للقطاع...");
            $curatedNews = $this->generateDirectSectorNewsViaGemini($tenant, 10);
        }

        if (empty($curatedNews)) {
            $log("  [!] تعذر استخراج أو تحليل الأخبار عبر الذكاء الاصطناعي. يرجى التحقق من ضبط مفتاح GEMINI_API_KEY في ملف .env.");
            Log::warning("IndustryNewsService: Gemini returned no curated news for tenant #{$tenant->id}.");
            return [];
        }

        $log("  [2/3] تم صياغة المقترحات لأهم " . count($curatedNews) . " أخبار. حفظ البيانات...");

        // 3. Persist to database inside transaction
        $persisted = TenantContext::withoutTenancy(function () use ($tenant, $batchDate, $curatedNews) {
            return DB::transaction(function () use ($tenant, $batchDate, $curatedNews) {
                // Remove existing records for this tenant & batch date to avoid duplicates
                TenantIndustryNews::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('batch_date', $batchDate)
                    ->delete();

                $records = [];
                foreach ($curatedNews as $idx => $item) {
                    $rank = (int)($item['rank'] ?? ($idx + 1));
                    $pubDate = null;
                    if (!empty($item['published_at'])) {
                        try {
                            $pubDate = Carbon::parse($item['published_at']);
                        } catch (\Throwable) {
                            $pubDate = Carbon::now('Asia/Riyadh');
                        }
                    }

                    $newsRecord = TenantIndustryNews::create([
                        'tenant_id' => $tenant->id,
                        'batch_date' => $batchDate,
                        'rank' => $rank,
                        'title' => (string)($item['title'] ?? 'خبر في القطاع'),
                        'summary' => (string)($item['summary'] ?? mb_substr($item['title'] ?? '', 0, 250)),
                        'source_name' => (string)($item['source_name'] ?? 'أخبار القطاع'),
                        'source_url' => $item['source_url'] ?? null,
                        'published_at' => $pubDate,
                        'category' => (string)($item['category'] ?? 'قرارات وتشريعات وزارية'),
                        'importance_score' => (float)($item['importance_score'] ?? 8.5),
                        'why_it_matters' => (string)($item['why_it_matters'] ?? ''),
                        'suggested_actions' => (array)($item['suggested_actions'] ?? []),
                        'status' => TenantIndustryNews::STATUS_UNREAD,
                    ]);

                    $records[] = $newsRecord;
                }

                return $records;
            });
        });

        $log("  [3/3] [✓] تم بنجاح حفظ " . count($persisted) . " خبراً ومقترحاً في قاعدة البيانات.");
        Log::info("IndustryNewsService: Successfully stored " . count($persisted) . " top news items for tenant #{$tenant->id} on {$batchDate}");

        return $persisted;
    }
}
