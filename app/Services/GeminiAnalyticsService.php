<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiAnalyticsService
{
    protected string $apiKey;
    protected string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';
    protected string $model = 'gemini-flash-latest';
    protected array $fallbackModels = ['gemini-flash-latest', 'gemini-3.6-flash'];

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key') ?: env('GEMINI_API_KEY') ?: env('GOOGLE_API_KEY', '');
    }

    /**
     * Call Gemini with multi-model fallback in case of 503 capacity or 429 rate limit errors.
     */
    protected function callGemini(array $messages, int $timeout = 120, array $responseFormat = ['type' => 'json_object']): ?string
    {
        $modelsToTry = array_unique(array_merge([$this->model], $this->fallbackModels));

        foreach ($modelsToTry as $candidateModel) {
            $payload = [
                'model' => $candidateModel,
                'messages' => $messages,
                'response_format' => $responseFormat,
            ];

            try {
                $response = $this->getHttpClient()
                    ->retry(2, 1000, throw: false)
                    ->withHeaders([
                        'Authorization' => "Bearer {$this->apiKey}",
                        'Content-Type' => 'application/json',
                    ])->timeout($timeout)->post($this->apiUrl, $payload);

                if ($response->successful()) {
                    return $response->json('choices.0.message.content', '{}');
                }

                $status = $response->status();
                Log::warning("Gemini model {$candidateModel} returned status {$status}", [
                    'body' => $response->body(),
                ]);

                if (in_array($status, [503, 429, 404])) {
                    continue;
                }
            } catch (\Exception $e) {
                Log::warning("Gemini model {$candidateModel} failed with exception: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Get configured HTTP client with Windows SSL revocation bypass and IPv4 priority.
     */
    protected function getHttpClient(): \Illuminate\Http\Client\PendingRequest
    {
        $curlOptions = defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NO_REVOKE')
            ? [
                CURLOPT_SSL_OPTIONS => CURLSSLOPT_NO_REVOKE,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]
            : [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ];

        return Http::withOptions([
            'curl' => $curlOptions,
            'verify' => false,
            'connect_timeout' => 30,
        ]);
    }

    /**
     * Format comments across platforms into user prompt.
     */
    protected function formatCommentsForPrompt(array $insta, array $fb, array $x, array $tiktok): string
    {
        $extractText = function (array $items): string {
            $texts = [];
            foreach ($items as $item) {
                if (is_array($item)) {
                    $texts[] = $item['text'] ?? $item['comment_text'] ?? json_encode($item, JSON_UNESCAPED_UNICODE);
                } else {
                    $texts[] = (string)$item;
                }
            }
            return implode("\n", $texts);
        };

        $out = "";
        $out .= "\n--- البلاتفورم هى Instagram ---\n" . $extractText($insta);
        $out .= "\n--- البلاتفورم هى Facebook ---\n" . $extractText($fb);
        $out .= "\n--- البلاتفورم هى X (Twitter) ---\n" . $extractText($x);
        $out .= "\n--- البلاتفورم هى TikTok ---\n" . $extractText($tiktok);

        return $out;
    }

    /**
     * Build System Prompt for Social Listening & Analytics Report.
     */
    protected function getSystemPrompt(): string
    {
        return <<<PROMPT
أنت محرك ذكاء اصطناعي متخصص في تحليل البيانات وتجميع التقارير التحليلية للسمعة الرقمية (Social Listening & Analytics).

مهمتك هي قراءة كافة التعليقات المرفقة والمصنفة حسب المنصة، ثم إخراج تقرير تجميعي شامل كـ JSON Object حصراً، بالهيكل والدقة التاليين:

{
  "overall_sentiment_summary": {
    "positive_count": 0,
    "negative_count": 0,
    "neutral_count": 0,
    "overall_sentiment": "إيجابي / سلبي / محايد"
  },
  "sentiment_by_platform": {
    "instagram": {
      "positive_count": 0,
      "negative_count": 0,
      "neutral_count": 0,
      "platform_sentiment": "إيجابي / سلبي / محايد"
    },
    "facebook": {
      "positive_count": 0,
      "negative_count": 0,
      "neutral_count": 0,
      "platform_sentiment": "إيجابي / سلبي / محايد"
    },
    "x": {
      "positive_count": 0,
      "negative_count": 0,
      "neutral_count": 0,
      "platform_sentiment": "إيجابي / سلبي / محايد"
    },
    "tiktok": {
      "positive_count": 0,
      "negative_count": 0,
      "neutral_count": 0,
      "platform_sentiment": "إيجابي / سلبي / محايد"
    }
  },
  "top_issues": [
    {
      "issue": "اسم المشكلة أو التحدي الرئيسي",
      "severity": "عالية / متوسطة / منخفضة",
      "count": 0,
      "platform": "اسم المنصة الأكثر تأثراً بها"
    }
  ],
  "keywords": ["كلمة1", "كلمة2", "كلمة3"],
  "key_comments": [
    {
      "comment_text": "نص التعليق المهم كما هو",
      "platform": "instagram / facebook / x / tiktok",
      "type": "شكوى حادة / إشادة قوية / اقتراح مهم",
      "reason": "سبب اختيار هذا التعليق كتعليق هام"
    }
  ]
}

التزم بالتالي:
1. إرجاع JSON مطابقة للهيكل أعلاه فقط بدون أي مقدمات أو شرح خارج الـ JSON.
2. إذا كانت هناك منصة لا تحتوي على تعليقات ممررة، ضع أعداد المشاعر الخاصة بها بـ 0 و platform_sentiment بـ "غير متوفر".
3. الكلمات المفتاحية keywords يجب أن تكون أبرز الأسماء والخدمات والمشاكل المتكررة عبر المنصات.
4. أهم التعليقات key_comments تشمل التعليقات ذات التأثير القوي مع تحديد المنصة التابع لها التعليق.
PROMPT;
    }

    /**
     * Send post comments to Gemini LLM for sentiment analytics.
     */
    public function analyzeSentiment(array $insta = [], array $fb = [], array $x = [], array $tiktok = []): array
    {
        if (empty($this->apiKey)) {
            Log::error("GOOGLE_API_KEY is missing in .env");
            return [];
        }

        $formattedComments = $this->formatCommentsForPrompt($insta, $fb, $x, $tiktok);
        $userMessage = "إليك قائمة التعليقات الكلية مقسمة حسب المنصات:\n" . $formattedComments;

        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt()],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $content = $this->callGemini($messages, 120);
        if ($content) {
            $cleaned = preg_replace('/```json|```/', '', $content);
            $json = json_decode(trim($cleaned), true);
            return is_array($json) ? $json : [];
        }

        return [];
    }

    // ==================== TOPIC TREND DISCOVERY & ANALYSIS ====================

    /**
     * Generate 8 keywords and 8 hashtags for an industry/topic using Gemini.
     */
    public function generateIndustrySearchQueries(string $industry): array
    {
        if (empty($this->apiKey)) {
            Log::error("GOOGLE_API_KEY is missing in .env");
            return [
                "keywords" => [$industry],
                "hashtags" => ["#" . str_replace(' ', '_', $industry)],
                "all_queries" => [$industry],
            ];
        }

        $prompt = <<<PROMPT
أنت خبير متقدم في الرصد الرقمي واكتشاف التريندات على شبكات التواصل (Social Listening & Trend Hunter).
الموضوع أو الكيان المستهدف بدقة متناهية: ("{$industry}").

المطلوب:
توليد أهم عبارات البحث والهاشتاجات الأكثر دقة وحداثة لرصد هذا الموضوع تحديداً على فيسبوك، تيك توك، وإكس:
التعليمات الصارمة:
1. حافظ دوماً على العبارة أو الجملة المستهدفة كاملة ("{$industry}") ولا تقم بتفكيكها إلى كلمات منفصلة أو مجردة.
2. الكلمات المفتاحية الناتجة يجب أن تتضمن الجملة كاملة أو عبارات مركبة مرتبطة بها مباشرة، بدون تجزئتها لكلمات فردية عشوائية.
3. ركز على زوايا التفاعل الحي للجمهور (مثال: أخبار، ملخص، ملخص مباراة، عروض، رأي الناس، تفاصيل).
4. قم بتوليد 6 عبارات مفتاحية (keywords) مركبة ودقيقة تتناول الجملة كاملة.
5. قم بتوليد 6 هاشتاجات (hashtags) نشطة ومباشرة متضمنة رمز # في البداية.
6. أرجع الناتج بتنسيق JSON حصراً بنفس الهيكل التالي وبدون أي مقدمات أو شروحات:

{
    "keywords": ["عبارة مركبة 1", "عبارة مركبة 2", "عبارة مركبة 3", "عبارة مركبة 4", "عبارة مركبة 5", "عبارة مركبة 6"],
    "hashtags": ["#هاشتاج1", "#هاشتاج2", "#هاشتاج3", "#هاشتاج4", "#هاشتاج5", "#هاشتاج6"]
}
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => $prompt],
        ];

        $content = $this->callGemini($messages, 60);
        if ($content) {
            $cleaned = preg_replace('/```json|```/', '', $content);
            $data = json_decode(trim($cleaned), true);

            $keywords = array_slice($data['keywords'] ?? [$industry], 0, 6);
            $hashtags = array_slice($data['hashtags'] ?? ["#" . str_replace(' ', '_', $industry)], 0, 6);
            $allQueries = array_values(array_unique(array_merge([$industry], $keywords, $hashtags)));

            return [
                "keywords" => $keywords,
                "hashtags" => $hashtags,
                "all_queries" => $allQueries,
            ];
        }

        return [
            "keywords" => [$industry],
            "hashtags" => ["#" . str_replace(' ', '_', $industry)],
            "all_queries" => [$industry],
        ];
    }

    /**
     * Cluster aggregated social media posts and Twitter trends into major trends with sentiment and solutions.
     */
    public function analyzeIndustryTrends(string $industry, array $rawData): array
    {
        if (empty($this->apiKey) || empty($rawData)) {
            return [
                "industry" => $industry,
                "trends_count" => 0,
                "trends_analysis" => [],
            ];
        }

        // Condense raw data to prevent token explosion while preserving context & engagement metrics
        $condensedData = array_map(function ($item) {
            return [
                "platform" => $item['platform'] ?? 'unknown',
                "author" => $item['username'] ?? $item['author'] ?? 'unknown',
                "content" => $item['text'] ?? $item['caption'] ?? $item['trend_name'] ?? '',
                "time" => $item['time'] ?? $item['created_at'] ?? null,
                "url" => $item['url'] ?? $item['trendUrl'] ?? null,
                "engagement" => $item['engagement'] ?? 0,
            ];
        }, $rawData);

        // Keep at most 300 representative items for prompt context
        $sampleData = array_slice($condensedData, 0, 300);
        $encodedData = json_encode($sampleData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
أنت خبير استراتيجي متقدم في تحليل بيانات شبكات التواصل والإنصات الرقمي (Social Listening & Trend Intelligence Specialist).
مجال/نشاط الشركة المستهدفة: ({$industry}).

إليك البيانات المجمعة من منصات التواصل الاجتماعي (Facebook, Instagram, TikTok, Twitter/X) مع التفاعلات:
{$encodedData}

ملاحظات هامة حول البيانات:
- منصات (Facebook, Instagram, TikTok) تحتوي على نصوص ومحتوى منشورات حقيقي (content) وعدد تفاعلات.
- منصة (Twitter/X) تحتوي على قائمة بأسماء وعناوين الترندات الأكثر تداولاً حالياً (content/trend_name).

المهام المطلوب منك تنفيذها بدقة واحترافية عالية:
1. اقرأ جميع النصوص وعناوين الترندات وقم بتجميع المواضيع المتشابهة تحت عنوان "ترند رئيسي" (Trend Cluster).
2. استبعد تماماً أي منشورات أو ترندات عامة لا تمت بصلة لمجال ({$industry}).
3. لكل ترند رئيسي تم اكتشافه:
   - trend_title: اسم أو عنوان واضح ومباشر للترند.
   - virality_score: درجة مؤشر انتشار التريند وقوته من (1 إلى 100).
   - virality_level: مستوى الانتشار ("مرتفع جداً" / "مرتفع" / "متوسط").
   - platforms: قائمة المنصات المكتشف بها.
   - sentiment: الانطباع العام للجمهور ("إيجابي" / "سلبي" / "محايد").
   - trend_summary: ملخص موجز ودقيق لما يدور حوله الترند.
   - actionable_recommendations: مصفوفة حتمية تضم من 2 إلى 3 مقترحات عمل وأفكار محتوى مخصصة ومبتكرة يناسب مجال الشركة ({$industry}) لركوب موجة الترند والاستفادة منه فوراً (مثال: إذا كان الترند مباراة كروية يُقترح إنشاء استوديو تحليلي أو مسابقة توقعات، إذا كان فوز شخصية يُقترح تقديم تهنئة رسمية، إذا كان عرض أو سعر يُقترح باقة ترويجية مناسبة).
   - actionable_solutions: 3 حلول عملية لإدارة الأزمة إذا كان الانطباع "سلبي" (أو null إذا لم يكن سلبياً).
   - positive_strategy: استراتيجية تسويقية وتوسعية إذا كان الانطباع "إيجابي" (أو null إذا لم يكن إيجابياً).

أرجع الناتج بتنسيق JSON حصراً وبناءً على الهيكل التالي فقط:
{
    "industry": "{$industry}",
    "trends_count": 0,
    "trends_analysis": [
        {
            "trend_title": "عنوان الترند المكتشف",
            "virality_score": 85,
            "virality_level": "مرتفع جداً",
            "platforms": ["facebook", "tiktok", "twitter"],
            "sentiment": "إيجابي",
            "trend_summary": "ملخص لما يدور حوله الترند...",
            "actionable_recommendations": [
                "فكرة محتوى/إجراء 1 (مثال: إنشاء استوديو تحليلي مباشر قبل المباراة)",
                "فكرة محتوى/إجراء 2 (مثال: نشر مسابقة تفاعلية لتوقع النتيجة مع الجمهور)"
            ],
            "actionable_solutions": null,
            "positive_strategy": "استراتيجية استغلال الزخم..."
        }
    ]
}
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => $prompt],
        ];

        $content = $this->callGemini($messages, 180);
        if ($content) {
            $cleaned = preg_replace('/```json|```/', '', $content);
            $result = json_decode(trim($cleaned), true);

            if (is_array($result)) {
                $analysis = $result['trends_analysis'] ?? [];
                $result['trends_count'] = count($analysis);
                return $result;
            }
        }

        return [
            "industry" => $industry,
            "trends_count" => 0,
            "trends_analysis" => [],
        ];
    }

    /**
     * Generate tactical AI recommendations, actionable advice, and suggested draft post for an individual post/article.
     */
    public function generatePostRecommendation(string $text, ?string $author = null, ?string $platform = null, ?string $sentiment = null): array
    {
        $authorInfo = $author ? "الناشر/المصدر: {$author}" : "";
        $platformInfo = $platform ? "المنصة: {$platform}" : "";
        $sentimentInfo = $sentiment ? "الانطباع: {$sentiment}" : "";

        $prompt = <<<PROMPT
أنت مستشار استراتيجي أول في إدارة مواقع التواصل الاجتماعي والتفاعل الرقمي وصناعة المحتوى (Social Media Strategist & Content Lead).

إليك تفاصيل منشور/خبر مرصود على شبكات التواصل الاجتماعي:
{$authorInfo}
{$platformInfo}
{$sentimentInfo}

نص المنشور/الخبر:
"""
{$text}
"""

المطلوب:
تحليل هذا المنشور بدقة وتقديم خطة تفاعل وصناعة محتوى ذكية لصاحب الحساب/الشركة، للإجابة على: (كيف يجب أن أتفاعل مع هذا الحدث أو المنشور؟ وما المنشور الذي يمكنني نشره لمواكبة الزخم؟)

قم بإرجاع كائن JSON بالهيكل التالي حصراً:
{
    "recommended_action": "توجيه وإجراء عملي وتكتيكي واضح ومباشر للتعامل مع هذا الحدث والاستفادة منه (مثال: نشر تعليق فوري، إعداد فيديو تحليلي، إطلاق مسابقة توقعات، استطلاع رأي)",
    "engagement_angle": "الزاوية التكتيكية للتفاعل (مثال: محتوى تحليلي ونقاش جماهيري / ركوب موجة التريند / تفاعل رياضي وتوقعات)",
    "suggested_post": "صيغة منشور احترافي وجذاب جاهز للنشر الفوري لمواكبة الحدث (متضمناً بداية مشوقة، تفاعل مع المتابعين، وهاشتاجات مناسبة)",
    "hashtags": ["#هاشتاج1", "#هاشتاج2", "#هاشتاج3"]
}
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => $prompt],
        ];

        $content = $this->callGemini($messages, 45);
        if ($content) {
            $cleaned = preg_replace('/```json|```/', '', $content);
            $result = json_decode(trim($cleaned), true);
            if (is_array($result) && !empty($result['recommended_action'])) {
                return $result;
            }
        }

        // Contextual smart fallback
        return [
            "recommended_action" => "نشر منشور تفاعلي سريع يواكب مجريات هذا الحدث، مع توجيه سؤال نقاشي للجمهور لزيادة التفاعل والمشاركات.",
            "engagement_angle" => "مواكبة الحدث وتفعيل النقاش الجماهيري",
            "suggested_post" => "ما رأيكم في مجريات هذه النتيجة؟ شاركونا توقعاتكم وآرائكم في التعليقات! 👇",
            "hashtags" => ["#تريند", "#تفاعل_معنا"],
        ];
    }
}

