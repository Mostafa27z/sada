<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiAnalyticsService
{
    protected string $apiKey;
    protected string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';
    protected string $model = 'gemini-2.5-flash';

    public function __construct()
    {
        $this->apiKey = env('GOOGLE_API_KEY', '');
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

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        try {
            $response = $this->getHttpClient()->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($this->apiUrl, $payload);

            if (!$response->successful()) {
                Log::error("Gemini API call failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $content = $response->json('choices.0.message.content', '{}');
            $json = json_decode($content, true);

            return is_array($json) ? $json : [];
        } catch (\Exception $e) {
            Log::error("Gemini Analytics Exception: " . $e->getMessage());
            return [];
        }
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
أنت خبير تسويق إلكتروني وتحليل شبكات اجتماعية.
المجال المستهدف: ({$industry}).

المطلوب:
قم بتوليد قائمة مخصصة لأهم مصطلحات البحث والهاشتاجات الأكثر تداولاً وانتشاراً حالياً والمتعلقة بمجال ({$industry}).

التعليمات والشروط:
1. قم بتوليد 8 كلمات مفتاحية (keywords) رئيسية وشائعة باللغتين العربية والإنجليزية.
2. قم بتوليد 8 هاشتاجات (hashtags) نشطة ومستهدفة متضمنة رمز الـ # في البداية (مثال: #ذكاء_اصطناعي).
3. أرجع النتيجة بتنسيق JSON حصراً بنفس الهيكل المحدد أدناه وبدون إضافة أي شرح أو نص خارجي.

هيكل الـ JSON المطلوب:
{
    "keywords": ["كلمة1", "كلمة2", "كلمة3", "كلمة4", "كلمة5", "كلمة6", "كلمة7", "كلمة8"],
    "hashtags": ["#هاشتاج1", "#هاشتاج2", "#هاشتاج3", "#هاشتاج4", "#هاشتاج5", "#هاشتاج6", "#هاشتاج7", "#هاشتاج8"]
}
PROMPT;

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        try {
            $response = $this->getHttpClient()
                ->retry(3, 2000, throw: false)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])->timeout(60)->post($this->apiUrl, $payload);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content', '{}');
                $cleaned = preg_replace('/```json|```/', '', $content);
                $data = json_decode(trim($cleaned), true);

                $keywords = array_slice($data['keywords'] ?? [$industry], 0, 8);
                $hashtags = array_slice($data['hashtags'] ?? ["#" . str_replace(' ', '_', $industry)], 0, 8);
                $allQueries = array_values(array_unique(array_merge($keywords, $hashtags)));

                return [
                    "keywords" => $keywords,
                    "hashtags" => $hashtags,
                    "all_queries" => $allQueries,
                ];
            }

            Log::error("Gemini query generation failed", ['body' => $response->body()]);
        } catch (\Exception $e) {
            Log::error("Gemini query generation exception: " . $e->getMessage());
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

        // Condense raw data to prevent token explosion while preserving context
        $condensedData = array_map(function ($item) {
            return [
                "platform" => $item['platform'] ?? 'unknown',
                "author" => $item['username'] ?? $item['author'] ?? 'unknown',
                "content" => $item['text'] ?? $item['caption'] ?? $item['trend_name'] ?? '',
                "time" => $item['time'] ?? $item['created_at'] ?? null,
                "url" => $item['url'] ?? $item['trendUrl'] ?? null,
            ];
        }, $rawData);

        // Keep at most 300 representative items for prompt context
        $sampleData = array_slice($condensedData, 0, 300);
        $encodedData = json_encode($sampleData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
أنت خبير تحليل بيانات الشبكات الاجتماعية (Social Listening & Trend Analyst).
المجال المستهدف: ({$industry}).

إليك البيانات المجمعة من منصات التواصل الاجتماعي (Facebook, Instagram, TikTok, Twitter/X):
{$encodedData}

ملاحظات هامة حول البيانات:
- منصات (Facebook, Instagram, TikTok) تحتوي على نصوص ومحتوى منشورات حقيقي (content).
- منصة (Twitter/X) تحتوي على قائمة بأسماء وعناوين الترندات الأكثر تداولاً حالياً (content/trend_name).

المهام المطلوب منك تنفيذها بدقة:
1. اقرأ جميع النصوص وعناوين الترندات وقم بتجميع المواضيع المتشابهة أو المرتبطة معاً تحت عنوان "ترند رئيسي" (Trend Cluster).
2. استبعد تماماً أي منشورات أو ترندات عامة لا تمت بصلة لمجال ({$industry}).
3. لكل ترند رئيسي نجحت في اكتشافه واستخراجه:
   - trend_title: اسم أو عنوان واضح للترند.
   - platforms: قائمة بالمنصات التي ظهر فيها هذا الترند (مثال: ["facebook", "tiktok"]).
   - sentiment: تحديد المشاعر أو الانطباع العام للجمهور تجاه الترند ("إيجابي" / "سلبي" / "محايد").
   - trend_summary: ملخص موجز ودقيق للترند وما يتحدث عنه الناس.
   - actionable_solutions: إذا كانت المشاعر "سلبي"، قدم مصفوفة بـ 3 حلول عملية ومباشرة للتعامل مع المشكلة وإدارة الأزمة. إذا لم تكن سلبية أرجع null.
   - positive_strategy: إذا كانت المشاعر "إيجابي"، قدم تعليقاً تحليلياً واستراتيجية تسويقية وتوسعية لاستغلال هذا الترند. إذا لم تكن إيجابية أرجع null.

أرجع الناتج بتنسيق JSON حصراً وبناءً على الهيكل التالي فقط:
{
    "industry": "{$industry}",
    "trends_count": 0,
    "trends_analysis": [
        {
            "trend_title": "عنوان الترند المكتشف",
            "platforms": ["facebook", "tiktok"],
            "sentiment": "سلبي",
            "trend_summary": "ملخص لما يدور حوله الترند...",
            "actionable_solutions": [
                "حل عملي 1",
                "حل عملي 2",
                "حل عملي 3"
            ],
            "positive_strategy": null
        }
    ]
}
PROMPT;

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        try {
            $response = $this->getHttpClient()
                ->retry(3, 2000, throw: false)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])->timeout(180)->post($this->apiUrl, $payload);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content', '{}');
                $cleaned = preg_replace('/```json|```/', '', $content);
                $result = json_decode(trim($cleaned), true);

                if (is_array($result)) {
                    $analysis = $result['trends_analysis'] ?? [];
                    $result['trends_count'] = count($analysis);
                    return $result;
                }
            }

            Log::error("Gemini trend analysis failed", ['body' => $response->body()]);
        } catch (\Exception $e) {
            Log::error("Gemini trend analysis exception: " . $e->getMessage());
        }

        return [
            "industry" => $industry,
            "trends_count" => 0,
            "trends_analysis" => [],
        ];
    }
}
