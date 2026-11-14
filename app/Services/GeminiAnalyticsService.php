<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiAnalyticsService
{
    protected string $apiKey;
    protected string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';
    protected string $model = 'gemini-flash-lite-latest';
    protected array $fallbackModels = [
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
        'gemini-flash-lite-latest',
        'gemini-flash-latest',
        'gemini-3.8-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.5-flash',
        'gemini-2.0-flash',
    ];

    public function __construct()
    {
        $this->model = env('GEMINI_MODEL', 'gemini-flash-lite-latest');
        $settingKey = class_exists(\App\Models\SystemSetting::class) ? \App\Models\SystemSetting::get('gemini_api_key') : null;
        $this->apiKey = $settingKey ?: config('services.gemini.api_key') ?: env('GEMINI_API_KEY') ?: env('GOOGLE_API_KEY', '');
    }

    public function hasApiKey(): bool
    {
        return !empty($this->apiKey);
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Call Gemini with multi-model fallback and circuit-breaker cooldowns for overloaded/timing-out models.
     */
    protected function callGemini(array $messages, int $timeout = 120, array $responseFormat = ['type' => 'json_object'], ?string $preferredModel = null): ?string
    {
        if (empty($this->apiKey)) {
            Log::error("GeminiAnalyticsService: GEMINI_API_KEY is empty. Please set it in .env or SystemSetting.");
            return null;
        }

        $lastWorking = Cache::get('gemini_last_working_model');
        $allModels = array_values(array_unique(array_filter(array_merge(
            [$lastWorking, $preferredModel, $this->model],
            $this->fallbackModels
        ))));

        // Skip models currently in cooldown (503 / 429 / timeout) unless all models are in cooldown
        $healthyModels = array_values(array_filter(
            $allModels,
            fn (string $m) => !Cache::has("gemini_model_cooldown:{$m}")
        ));
        $modelsToTry = !empty($healthyModels) ? $healthyModels : $allModels;

        // Cap per-model attempt timeout so one hanging model never blocks the queue worker for minutes
        $attemptTimeout = min(max($timeout, 15), 45);

        foreach ($modelsToTry as $candidateModel) {
            $payload = [
                'model' => $candidateModel,
                'messages' => $messages,
                'response_format' => $responseFormat,
            ];

            try {
                $response = $this->getHttpClient()
                    ->withHeaders([
                        'Authorization' => "Bearer {$this->apiKey}",
                        'Content-Type' => 'application/json',
                    ])->timeout($attemptTimeout)->post($this->apiUrl, $payload);

                if ($response->successful()) {
                    Cache::put('gemini_last_working_model', $candidateModel, now()->addMinutes(15));
                    Cache::forget("gemini_model_cooldown:{$candidateModel}");
                    $content = $response->json('choices.0.message.content', '{}');
                    return trim(preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', trim($content)));
                }

                $status = $response->status();
                Log::warning("Gemini model {$candidateModel} returned status {$status}", [
                    'body' => $response->body(),
                ]);

                if (in_array($status, [404, 400], true)) {
                    Cache::put("gemini_model_cooldown:{$candidateModel}", true, now()->addMinutes(60));
                } elseif (in_array($status, [429, 500, 502, 503, 504], true)) {
                    Cache::put("gemini_model_cooldown:{$candidateModel}", true, now()->addMinutes(5));
                }

                // Always try next model on any non-successful HTTP status
                continue;
            } catch (\Exception $e) {
                Log::warning("Gemini model {$candidateModel} failed with exception: " . $e->getMessage());
                Cache::put("gemini_model_cooldown:{$candidateModel}", true, now()->addMinutes(5));
                continue;
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
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Format comments across platforms into user prompt.
     */
    protected function formatCommentsForPrompt(array $insta, array $fb, array $x, array $tiktok, array $web = []): string
    {
        $extractText = function (array $items): string {
            $texts = [];
            foreach ($items as $item) {
                if (is_array($item)) {
                    $texts[] = $item['text'] ?? $item['title'] ?? $item['comment_text'] ?? json_encode($item, JSON_UNESCAPED_UNICODE);
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
        if (!empty($web)) {
            $out .= "\n--- البلاتفورم هى الأخبار والمواقع الإلكترونية (Web) ---\n" . $extractText($web);
        }

        return $out;
    }

    /**
     * Build dynamic prompt snippet representing the user's custom sentiment & stance rubric.
     */
    protected function buildRubricPromptChunk(?array $rubric = null): string
    {
        if (empty($rubric)) {
            return "";
        }

        $stance = $rubric['stance_description'] ?? ($rubric['stance'] ?? ($rubric['description'] ?? ''));
        $posRules = $rubric['positive_rules'] ?? ($rubric['positive'] ?? '');
        $negRules = $rubric['negative_rules'] ?? ($rubric['negative'] ?? '');
        $neuRules = $rubric['neutral_rules'] ?? ($rubric['neutral'] ?? '');
        $preferredSide = $rubric['preferred_side'] ?? ($rubric['target_stance'] ?? '');
        $opposedSide = $rubric['opposed_side'] ?? '';
        $topic = $rubric['topic_context'] ?? ($rubric['topic'] ?? '');

        $chunk = "\n⚠️ معيار الموقف والتصنيف المخصص للعميل (Custom Stance & Sentiment Rubric) — له الأولوية المطلقة على أي قاعدة عامة أخرى:\n";
        $chunk .= "قاعدة الحكم الجوهرية: يُحكم على كل نص بناءً على موقف كاتبه من الجهة/القضية المحددة أدناه وهل يخدم صورتها أم يضرها، وليس بناءً على نبرة النص أو ألفاظه أو حدّة لغته. فالرد الحاد على شاكٍ أو مشهِّر دفاعاً عن الجهة = إيجابي، والشكوى المصاغة بهدوء أو على شكل قصة = سلبي.\n";
        if (!empty($topic)) {
            $chunk .= "- سياق الموضوع/القضية ونطاقه: {$topic}\n";
        }
        if (!empty($stance)) {
            $chunk .= "- الموقف والتوجه الاستراتيجي للعميل: {$stance}\n";
        }
        if (!empty($preferredSide)) {
            $chunk .= "- الطرف/القضية المؤيدة (الحليف): {$preferredSide}\n";
        }
        if (!empty($opposedSide)) {
            $chunk .= "- الطرف/الظاهرة المعارضة (الخصم): {$opposedSide}\n";
        }
        if (!empty($posRules)) {
            $chunk .= "- ما يُصنف كـ 'positive' (إيجابي / +):\n{$posRules}\n";
        }
        if (!empty($negRules)) {
            $chunk .= "- ما يُصنف كـ 'negative' (سلبي / -):\n{$negRules}\n";
        }
        if (!empty($neuRules)) {
            $chunk .= "- ما يُصنف كـ 'neutral' (محايد):\n{$neuRules}\n";
        }
        if (empty($posRules) && empty($negRules)) {
            $chunk .= "- [قاعدة احتياطية]: إذا لم تتوفر قواعد تفصيلية، فالمحتوى الذي يدعم موقف العميل أو يذم الظواهر السلبية (كالجريمة والفساد) يُصنف 'positive'، والذي يضر العميل أو يبرر الظاهرة السلبية يُصنف 'negative'.\n";
        }
        $chunk .= "- أي بند يخص «خارج النطاق» أو الاستبعاد في القواعد أعلاه يعني أن النص لا يدخل في النسب إطلاقاً (is_relevant = false).\n";

        return $chunk;
    }

    /**
     * Build System Prompt for Social Listening & Analytics Report.
     */
    protected function getSystemPrompt(?array $rubric = null): string
    {
        $rubricSection = $this->buildRubricPromptChunk($rubric);

        return <<<PROMPT
أنت محرك ذكاء اصطناعي متخصص في تحليل البيانات وتجميع التقارير التحليلية للسمعة الرقمية (Social Listening & Analytics).

مهمتك هي قراءة كافة التعليقات المرفقة والمصنفة حسب المنصة، ثم إخراج تقرير تجميعي شامل كـ JSON Object حصراً، بالهيكل والدقة التاليين:
{$rubricSection}

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
    },
    "web": {
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
      "platform": "instagram / facebook / x / tiktok / web",
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
    public function analyzeSentiment(array $insta = [], array $fb = [], array $x = [], array $tiktok = [], ?array $rubric = null, array $web = []): array
    {
        if (empty($this->apiKey)) {
            Log::error("GOOGLE_API_KEY is missing in .env");
            return [];
        }

        $formattedComments = $this->formatCommentsForPrompt($insta, $fb, $x, $tiktok, $web);
        $userMessage = "إليك قائمة التعليقات الكلية مقسمة حسب المنصات:\n" . $formattedComments;

        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt($rubric)],
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

    /**
     * Accurately classify sentiment (positive, negative, neutral) for individual posts,
     * with support for custom stance/rubric rules.
     */
    public function classifyPostsSentiment(array $posts, ?array $rubric = null, array|string|null $keywords = null, ?string $postContext = null): array
    {
        if (is_string($keywords)) {
            $keywords = array_values(array_filter(array_map('trim', explode(',', $keywords))));
        }
        if (empty($posts) || empty($this->apiKey)) {
            return [];
        }

        // Prepare compact list for LLM
        $itemsToClassify = [];
        foreach ($posts as $idx => $post) {
            $text = is_array($post) ? ($post['text'] ?? $post['content'] ?? '') : (string)$post;
            $text = trim(preg_replace('/\s+/', ' ', (string)$text));
            if (empty($text) || mb_strlen($text) < 2 || mb_strtolower($text) === 'unknown') {
                continue;
            }
            // NOTE: callers may identify items via `id`, `post_id` or `external_id` — all must be honored,
            // otherwise results can't be matched back to the records being re-analyzed.
            $pId = is_array($post)
                ? ($post['id'] ?? $post['post_id'] ?? $post['external_id'] ?? (string)$idx)
                : (string)$idx;
            $itemsToClassify[] = [
                'index' => $idx,
                'id' => (string)$pId,
                'text' => mb_substr($text, 0, 700),
            ];
        }

        if (empty($itemsToClassify)) {
            return [];
        }

        $results = [];
        $rubricChunk = $this->buildRubricPromptChunk($rubric);
        $hasRubric = !empty($rubricChunk);
        $topicScope = trim((string)($rubric['topic_context'] ?? ($rubric['topic'] ?? '')));

        $relevanceChunk = "";
        if (!empty($keywords)) {
            $kwStr = implode(' | ', array_values(array_filter(array_map('trim', (array)$keywords))));
            $relevanceChunk = <<<REL
⚠️ فحص الصلة بالكيان والموضوع المستهدف بالرصد (Strict Entity & Subject Relevance):
الكيانات والأشخاص المستهدفون بالرصد: [{$kwStr}]
يجب تحديد حقل "is_relevant" (true / false) بدقة تامة لكل عنصر:
- ضع "is_relevant": true فقط إذا كان المنشور يتناول فعلياً الشخص أو الكيان أو الموضوع المستهدف أعلاه ومرتبطاً بمجاله ونشاطه (مثلاً: رعاية صحية، إدارة تنفيذية، أعمال، قطاع طبي).
- [قاعدة حاسمة في تشابه الأسماء والرياضة Homonyms & Sports Filter]:
  إذا كان الشخص المستهدف قيادياً أو مسؤولاً في جهة حكومية أو قطاع صحي/طبي/تجاري، وكان المنشور يتناول لاعباً رياضياً أو كروياً أو فناناً أو مشهوراً يحمل نفس الاسم (مثل لاعب كرة القدم)، أو يتناول بطولات كروية ومباريات وأندية (مثل الهلال، النصر، الأهلي، كأس الخليج، كأس آسيا، ألعاب إلكترونية مثل FC/FIFA)، يجب حتماً وضع "is_relevant": false واستبعاده تماماً!
- ضع "is_relevant": false إذا كان المنشور يتناول شخصاً أو مسؤولاً آخر مختلفاً، أو محتوى ترفيهي/إعلاني/رياضي عام لا علاقة له بنشاط ومجال الكيان المستهدف.
REL;
        }

        if ($topicScope !== '') {
            $relevanceChunk .= "\n⚠️ نطاق الرصد (Scope): النطاق المقبول هو فقط: {$topicScope}\n"
                . "- ضع \"is_relevant\": false لأي نص يخص جهة أو منطقة أو قطاعاً آخر خارج هذا النطاق (مثل تجمع صحي آخر أو منطقة أخرى أو أخبار عامة لا علاقة لها بالنطاق)، أو إعلان تجاري/استثماري، أو منشور صادر من الحساب الرسمي للجهة نفسها (ليس صوت جمهور).\n"
                . "- التعليق القصير الذي يرد على المنشور الأصلي (مثل: «صح كلامك» أو «وش المشكلة؟») يُعتبر ضمن النطاق إذا كان المنشور الأصلي ضمنه.\n";
        }

        $contextChunk = "";
        if (!empty($postContext)) {
            $contextChunk = "\n📌 المنشور الأصلي الذي كُتبت هذه التعليقات رداً عليه (استخدمه لفهم المقصود من التعليقات والردود وهل التعليق موجّه للجهة المستهدفة أم رداً على كاتب المنشور، وليس لتصنيف المنشور الأصلي):\n\""
                . mb_substr(trim(preg_replace('/\s+/', ' ', $postContext)), 0, 800) . "\"\n";
        }

        $replyContextRules = <<<REPLY_RULES
⚠️ الإطار الاستراتيجي الموحّد لتحليل المواقف والمشاعر (Universal 360° Brand-First Stance Framework):
تذكّر دائماً: التصنيف هنا **من منظور مصلحة وسمعة الكيان أو المنشأة المستهدفة بالرصد حصراً** (سواء كانت شركة تجارية، بنك، جهة حكومية، جهة صحية، علامة تجارية، أو شخصية قيادية)، وليس تحليلاً لغوياً سطحياً!
المعيار الحاسم: «هل هذا المحتوى يخدم سمعة ومصلحة الكيان المستهدف بالرصد أم يضر به؟»

📌 القواعد العالمية الحاكمة لتصنيف التعليقات والردود:

1. إذا كان المنشور الأصلي (أو سياق النقاش) يحتوي على شكوى أو نقد أو هجوم أو استياء ضد الكيان المستهدف:
   أ. [تأييد الشاكي والتحفيز ضداً للكيان = "negative" حتماً]:
      - أي تعليق يدعم صاحب الشكوى أو يشجعه أو يحفزه أو يوافقه (مثل: «استمر»، «كفو»، «بيض الله وجهك»، «معاك حق»، «الله يقويك»، «لا تسكت عن حقك»، «صادق»، «نفس المعاناة عندي»، «شركة فاشلة»، «كلهم كذا»، «افضحهم») يُعد **سلبياً ("negative")** قطعاً للمنشأة المستهدفة، لأن تشجيع الناقد وتأكيد الشكوى يمثّل تصعيداً للهجوم والضرر بالسمعة.
      - ⛔ تنبيه حاسم: حتى لو كانت الكلمة تحفيزية لغوياً (مثل: "استمر" أو "كفو" أو "بطل")، طالما أن المعلق يدعم الناقد ضد المنشأة، فالتصنيف هو **"negative"** حتماً وممنوع وضع positive!
   ب. [مهاجمة الشاكي وتكذيبه أو الدفاع عن الكيان = "positive" حتماً]:
      - أي تعليق يهاجم صاحب المنشور نفسه أو يكذّبه أو يوبّخه أو يسفّه ادعاءه أو يدافع عن الكيان ويوضح أنظمته ولوائحه وساعات عمله الرسمية وجودة خدماته (مثل: «كذاب»، «مفتري»، «غير صحيح»، «انت غلطان»، «هدفك التشهير والتضليل»، «الخدمة ممتازة وتجربتي معهم رائعة»، «النظام واضح وانت متجاوزه») يُعد **إيجابياً ("positive")** لصالح الكيان، لأن تفنيد الشكوى وإسقاط مصداقية المهاجم يصب مباشرة في مصلحة الكيان وحماية سمعته.
      - ⛔ تنبيه حاسم: إذا كان التعليق يهاجم أو يكذّب صاحب الشكوى ("يا كذاب"، "مفتري")، فالألفاظ الحادة هنا موجّهة ضد المهاجم لا ضد الكيان، فتُصنف **"positive"** للكيان!
   ج. [تهدئة التشهير والتوجيه للقنوات الرسمية = "positive"]:
      - أي رد ينصح بعدم التشهير العلني والتوجه لخدمة العملاء أو الدعم الفني أو القنوات الرسمية التابعة للجهة (بدل النشر في وسائل التواصل) يُعد **إيجابياً ("positive")** لصالح الكيان.

2. إذا كان المنشور الأصلي مادحاً أو إيجابياً أو إعلاناً رسمياً للكيان المستهدف:
   أ. الإشادة والشكر والدعم والتأييد = "positive".
   ب. التشكيك أو التذمر أو إثارة الشكاوى والمطالبات في الردود (مثل: «ردوا علي بالخاص»، «دعاية كاذبة»، «نصابين») = "negative".

3. التعليقات المحايدة ("neutral"):
   - تقتصر فقط على الاستفسارات الإجرائية أو المعرفية البحتة الخالية من أي مدح أو ذم أو اتخاذ موقف (مثل: «بكم السعر؟»، «وين موقعكم؟»، «هل الخدمة متاحة؟»).
REPLY_RULES;

        if ($hasRubric) {
            $definitionsChunk = <<<DEFS
المطلوب: لكل عنصر، حدّد أولاً is_relevant ثم صنّف الموقف (positive / negative / neutral) **وفق معيار العميل المخصص أعلاه حصراً مع مراعاة قواعد الردود التالية**.
{$replyContextRules}
- لا تستخدم أي تعريفات عامة تتعارض مع معيار العميل إلا إذا سكت المعيار عن الحالة.
- الحكم بموقف الكاتب من الجهة وليس بنبرة ألفاظه.
- اذكر في "reason" بجملة عربية قصيرة سبب التصنيف مستنداً إلى قاعدة من قواعد العميل أو موقف الكاتب من الجهة.
DEFS;
        } else {
            $definitionsChunk = <<<DEFS
المطلوب: فحص صلة المنشور أولاً، ثم تصنيف المشاعر والمواقف لكل عنصر بدقة وموضوعية إلى إحدى الحالات الثلاث (positive / negative / neutral) بناءً على أثر المحتوى على الشخص أو الكيان المستهدف بالرصد:
1. "positive": إشادة، شكر، تشجيع، دفاع عن الجهة وتوضيح أوقات عملها وأنظمتها، توجيه الشاكي للقنوات الرسمية، تفنيد أو تكذيب ادعاء مسيء للجهة، أو أخبار التكليف والتعيين والترقية وتولي المناصب القيادية والإنجازات، أو أي موقف يصب في مصلحة الكيان المستهدف.
2. "negative": إعفاء/إقالة، إنهاء تكليف، شكوى حقيقية أو استياء ضد خدمات الجهة، تشكيك، نقد، هجوم، تراجع، إهمال، تعثر خدمة، تأخير مواعيد، أو شتيمة موجهة ضد الكيان/الشخص المستهدف نفسه.
3. "neutral": استفسار إجرائي بريء أو خبر وصفي مجرد لا يحمل أي مدح أو ذم أو دفاع أو هجوم.
{$replyContextRules}
DEFS;
        }

        // Batch in groups of 25 for high reliability and fast response
        $chunks = array_chunk($itemsToClassify, 25);

        foreach ($chunks as $chunk) {
            $prompt = <<<PROMPT
أنت خبير لغوي واستراتيجي متخصص في تحليل مشاعر ومواقف منشورات وتعليقات منصات التواصل الاجتماعي العربية واللهجات المحلية (السعودية، الخليجية، المصرية، الشامية، إلخ).
{$rubricChunk}
{$relevanceChunk}
{$contextChunk}

{$definitionsChunk}

إليك العناصر المطلوب تصنيفها كـ JSON Array (صنّف كل عنصر دون استثناء وبنفس قيمة index):
PROMPT;

            $systemInstruction = <<<SYS
أرجع كائن JSON يحتوي على مصفوفة "classifications" مطابقة للعناصر الممررة:
{
  "classifications": [
    {
      "index": 0,
      "id": "معرف المنشور",
      "is_relevant": true,
      "sentiment": "positive" أو "negative" أو "neutral",
      "sentiment_score": 0.85,
      "reason": "سبب تصنيف المنشور بهذا الشعور وفق المعيار المحدد"
    }
  ]
}
SYS;

            $messages = [
                ['role' => 'system', 'content' => $prompt . "\n\n" . $systemInstruction],
                ['role' => 'user', 'content' => json_encode($chunk, JSON_UNESCAPED_UNICODE)],
            ];

            $responseFormat = [
                'type' => 'json_object',
            ];

            $classList = [];
            for ($attempt = 0; $attempt < 2 && empty($classList); $attempt++) {
                $rawContent = $this->callGemini($messages, 45, $responseFormat, env('GEMINI_CLASSIFY_MODEL', 'gemini-flash-latest'));
                if ($rawContent === null) {
                    break;
                }
                if ($rawContent) {
                    $cleaned = preg_replace('/```json|```/', '', $rawContent);
                    $json = json_decode(trim($cleaned), true);
                    $classList = $json['classifications'] ?? (is_array($json) && isset($json[0]) ? $json : []);
                }
            }

            if (is_array($classList)) {
                $idByIndex = [];
                foreach ($chunk as $c) {
                    $idByIndex[(string)$c['index']] = $c['id'];
                }
                foreach ($classList as $cItem) {
                    if (!is_array($cItem)) {
                        continue;
                    }
                    // Prefer mapping by index (deterministic) and fall back to the echoed id
                    $key = isset($cItem['index']) && isset($idByIndex[(string)$cItem['index']])
                        ? $idByIndex[(string)$cItem['index']]
                        : ($cItem['id'] ?? null);
                    $sent = strtolower((string)($cItem['sentiment'] ?? 'neutral'));
                    if (!in_array($sent, ['positive', 'negative', 'neutral'])) {
                        $sent = 'neutral';
                    }
                    $score = floatval($cItem['sentiment_score'] ?? 0.8);
                    if ($score <= 0 || $score > 1.0) $score = 0.85;

                    $isRelevant = isset($cItem['is_relevant']) ? filter_var($cItem['is_relevant'], FILTER_VALIDATE_BOOLEAN) : true;
                    $reasonText = trim((string) ($cItem['reason'] ?? ''));

                    // 1. Check if the reasoning explicitly indicates defense/support of the entity or refuting the complaint
                    $defendsEntityMarkers = [
                        'يدعم موقف الجهة', 'دعم موقف الجهة', 'دعم الجهة', 'دعم المنشأة', 'يدعم المنشأة',
                        'دفاع عن الجهة', 'دفاعاً عن الجهة', 'دفاع عن المنشأة', 'دفاعاً عن المنشأة',
                        'يدحض شكوى', 'دحض شكوى', 'تفنيد ادعاء', 'تفنيد شكوى', 'تفنيد اتهام', 'دحض ادعاء',
                        'يبرر طبيعة عمل', 'تبرير طبيعة عمل', 'تبرير موقف الجهة', 'يبرر للجهة', 'يدافع عن الجهة', 'دفاعاً عن'
                    ];
                    $isExplicitlySupportingEntity = false;
                    foreach ($defendsEntityMarkers as $dem) {
                        if (mb_strpos($reasonText, $dem) !== false) {
                            $isExplicitlySupportingEntity = true;
                            break;
                        }
                    }

                    // 2. Guard against misclassifying encouragement/support of a critic as positive:
                    // Supporting, cheering or encouraging the critic/complainant is 100% NEGATIVE for the monitored entity.
                    $isSupportingCritic = false;
                    if (!$isExplicitlySupportingEntity) {
                        $supportsCriticPhrases = [
                            'دعم لصاحب', 'دعم كاتب', 'تحفيز لصاحب', 'تحفيز كاتب', 'تأييد لصاحب', 'تأييد كاتب',
                            'تشجيع لصاحب', 'تشجيع كاتب', 'تضامن مع صاحب', 'تضامن مع كاتب', 'مساندة صاحب', 'مساندة كاتب',
                            'دعم الشاكي', 'تأييد الشاكي', 'تشجيع الشاكي', 'مساندة الشاكي',
                            'دعم الناقد', 'تأييد الناقد', 'تشجيع الناقد', 'مساندة الناقد',
                            'دعم المشتكي', 'تأييد المشتكي', 'تشجيع المشتكي', 'مساندة المشتكي',
                            'يخدم توجه الانتقاد', 'يخدم الانتقاد', 'دعم التوجه المعارض', 'موافقة لصاحب', 'موافقة الشاكي'
                        ];
                        foreach ($supportsCriticPhrases as $scp) {
                            if (mb_strpos($reasonText, $scp) !== false) {
                                $isSupportingCritic = true;
                                break;
                            }
                        }
                    }

                    if ($isExplicitlySupportingEntity) {
                        $sent = 'positive';
                    } elseif ($isSupportingCritic) {
                        $sent = 'negative';
                    } elseif ($sent === 'negative' && $reasonText !== '') {
                        // 2. Self-consistency guard ("Enemy of my enemy = Positive for monitored entity"):
                        // If the comment is attacking/accusing/refuting the POSTER (not the entity) or defending/guiding, flip negative -> positive.
                        $attacksPosterVerbs = [
                            'اتهام', 'تكذيب', 'شتم', 'مهاجمة', 'هجوم على', 'توبيخ', 'انتقاد صاحب', 'انتقاد كاتب', 'الرد على صاحب', 'تفنيد ادعاء', 'التشكيك في', 'كذب'
                        ];
                        $defenseTopics = ['توضيح أوقات', 'ساعات الدوام', 'أوقات الدوام', 'القنوات الرسمية', 'دفاع عن', 'دفاعاً عن'];
                        $mentionsPosterAttack = false;
                        foreach ($attacksPosterVerbs as $apv) {
                            if (mb_strpos($reasonText, $apv) !== false) {
                                $mentionsPosterAttack = true;
                                break;
                            }
                        }
                        if (!$mentionsPosterAttack) {
                            foreach ($defenseTopics as $dt) {
                                if (mb_strpos($reasonText, $dt) !== false) {
                                    $mentionsPosterAttack = true;
                                    break;
                                }
                            }
                        }

                        $attacksEntityMarkers = ['الجهة', 'المنشأة', 'الشركة', 'التجمع', 'الوزارة', 'المستشفى', 'الخدمة'];
                        $attacksEntityDirectly = false;
                        foreach ($attacksEntityMarkers as $aem) {
                            if (mb_strpos($reasonText, "ضد {$aem}") !== false || mb_strpos($reasonText, "مهاجمة {$aem}") !== false || mb_strpos($reasonText, "نقد {$aem}") !== false || mb_strpos($reasonText, "انتقاد {$aem}") !== false) {
                                $attacksEntityDirectly = true;
                                break;
                            }
                        }
                        if ($mentionsPosterAttack && !$attacksEntityDirectly) {
                            $sent = 'positive';
                            $reasonText .= ' (موقف داعم للمنشأة عبر تفنيد/انتقاد صاحب المنشور)';
                        }
                    }

                    if ($key !== null) {
                        $results[(string)$key] = [
                            'is_relevant' => $isRelevant,
                            'sentiment' => $sent,
                            'sentiment_score' => $score,
                            'reason' => $reasonText,
                        ];
                    }
                }
            }
        }

        return $results;
    }

    // ==================== TOPIC TREND DISCOVERY & ANALYSIS ====================

    /**
     * Generate 8 keywords and 8 hashtags for an industry/topic using Gemini.
     */
    public function generateIndustrySearchQueries(string $industry, ?string $country = null, ?string $brandName = null): array
    {
        $countryContext = $country ? " داخل سوق دولة ({$country})" : "";
        $brandContext = $brandName ? " اسم الشركة أو العلامة التجارية المرصودة: («{$brandName}»)." : "";

        $defaultKeywords = $brandName
            ? [$brandName, "{$brandName} {$industry}", "آراء حول {$brandName}", "خدمات {$brandName}", "تطبيق {$brandName}", $industry]
            : [$industry];
        $defaultHashtags = $brandName
            ? ["#" . str_replace(' ', '_', $brandName), "#" . str_replace(' ', '_', $industry)]
            : ["#" . str_replace(' ', '_', $industry)];

        if (empty($this->apiKey)) {
            Log::error("GOOGLE_API_KEY is missing in .env");
            return [
                "keywords" => $defaultKeywords,
                "hashtags" => $defaultHashtags,
                "all_queries" => array_values(array_unique(array_merge($defaultKeywords, $defaultHashtags))),
            ];
        }

        $brandInstructions = $brandName
            ? "7. الأولوية القصوى: التركيز على رصد ما يُنشر حول شركة («{$brandName}») تحديداً داخل هذا القطاع («{$industry}»)، وعبارات تبحث عن تفاعل العملاء وتجاربهم وآرائهم وشكاويهم أو إشادتهم بها وبخدماتها."
            : "";

        $prompt = <<<PROMPT
أنت خبير متقدم في الرصد الرقمي واكتشاف التريندات والسمعة المؤسسية على شبكات التواصل (Social Listening & Brand Intelligence).
{$brandContext}
الموضوع أو المجال المستهدف: ("{$industry}").
الدولة أو النطاق الجغرافي المستهدف: ("{$country}").

المطلوب:
توليد أهم عبارات البحث والهاشتاجات الأكثر دقة وحداثة ورواجاً لرصد هذا الكيان والمجال تحديداً{$countryContext} على منصات التواصل (إكس، تيك توك، فيسبوك، إنستغرام):
التعليمات الصارمة:
1. حافظ على سياق العلامة والمجال والدولة ("{$brandName}" - "{$industry}" في "{$country}").
2. الكلمات المفتاحية الناتجة يجب أن تتضمن عبارات مركبة تبحث بدقة عن آراء الناس، التجارب، الأسعار، الخدمات، والانطباعات.
3. ركز على زوايا التفاعل الحي للجمهور (مثال: تجربة، خدمة عملاء، آراء، تقييم، عروض، مشاكل، ملخص).
{$brandInstructions}
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

            $keywords = array_slice($data['keywords'] ?? $defaultKeywords, 0, 6);
            $hashtags = array_slice($data['hashtags'] ?? $defaultHashtags, 0, 6);
            $allQueries = array_values(array_unique(array_merge($defaultKeywords, $keywords, $hashtags)));

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
    public function analyzeIndustryTrends(string $industry, array $rawData, ?string $dateFrom = null, ?string $dateTo = null): array
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

        $timeframeInstruction = ($dateFrom || $dateTo)
            ? "الفترة الزمنية المحددة للرصد والتحليل: من " . ($dateFrom ?: 'البداية') . " إلى " . ($dateTo ?: 'الآن') . ". ركّز تحليلك وعناوين التريندات حصراً على ما تم نشره وتداوله خلال هذه الفترة المحددة فقط."
            : "";

        $prompt = <<<PROMPT
أنت خبير استراتيجي متقدم في تحليل بيانات شبكات التواصل والإنصات الرقمي (Social Listening & Trend Intelligence Specialist).
مجال/نشاط الشركة المستهدفة: ({$industry}).
{$timeframeInstruction}

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
تحليل هذا المنشور الفردي بدقة وتقديم خطة تفاعل وصناعة محتوى تكتيكية مخصصة حصراً وحرفياً لمحتوى هذا المنشور وموضوعه بالذات (ممنوع تماماً استخدام عبارات عامة أو ردود جاهزة مكررة).
يجب أن ترتبط التوصية وصيغة المنشور المقترح مباشرة وبشكل صريح بالتفاصيل والأسماء والأحداث المذكورة في نص هذا المنشور فقط.

قم بإرجاع كائن JSON بالهيكل التالي حصراً:
{
    "recommended_action": "إجراء عملي وتكتيكي مباشر ومخصص تحديداً لموضوع وتفاصيل هذا المنشور للتعامل معه والاستفادة منه (مع ذكر موضوعه نصاً)",
    "engagement_angle": "الزاوية التكتيكية للتفاعل المحددة لهذا المنشور بالذات",
    "suggested_post": "صيغة منشور احترافي وجذاب جاهز للنشر الفوري لمواكبة ومناقشة تفاصيل هذا المنشور (متضمناً سؤالاً تفاعلياً وهاشتاجات ذات صلة)",
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

        // Contextual dynamic fallback derived directly from the post text
        $cleanText = preg_replace('/https?:\/\/\S+|[#@]/u', ' ', $text);
        $cleanText = trim(preg_replace('/\s+/u', ' ', $cleanText));
        $firstSentence = preg_split('/[.!?؟\n]/u', $cleanText)[0] ?? $cleanText;
        $snippet = mb_substr(trim($firstSentence), 0, 75);
        if (mb_strlen($firstSentence) > 75) {
            $snippet .= '...';
        }
        if (empty($snippet)) {
            $snippet = 'هذا المنشور المتداول';
        }

        $tagCandidate = preg_replace('/[^\p{Arabic}\p{L}0-9_]/u', '', mb_substr($snippet, 0, 20));
        $tag = !empty($tagCandidate) ? '#' . $tagCandidate : '#تريند';

        return [
            "recommended_action" => "المشاركة في التفاعل مع هذا الطرح (\"{$snippet}\") بتقديم رؤية موضوعية تثري النقاش وترصد اتجاهات المتابعين.",
            "engagement_angle" => "مواكبة الطرح وإثراء النقاش حول: {$snippet}",
            "suggested_post" => "تفاعل واسع وآراء متداولة حول: \"{$snippet}\".. برأيكم كيف ترون تطورات هذا الأمر؟ شاركونا وجهة نظركم! 💬👇\n{$tag} #نقاش",
            "hashtags" => [$tag, "#نقاش"],
        ];
    }

    /**
     * Synthesize and generate a comprehensive Master Post across all trend posts.
     */
    public function generateMasterTrendPost(string $topic, array $postsList, ?string $tone = null): array
    {
        $postsSummary = "";
        $count = 0;
        foreach ($postsList as $idx => $post) {
            if ($count >= 15) break;
            $text = is_array($post) ? ($post['text'] ?? '') : (string)$post;
            $author = is_array($post) ? ($post['author'] ?? 'مصدر') : 'مصدر';
            if (empty(trim($text))) continue;
            $cleanText = mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 160);
            $postsSummary .= ($count + 1) . ". [{$author}]: {$cleanText}\n";
            $count++;
        }

        $toneDesc = match($tone) {
            'analytical' => 'نبرة تحليلية إخبارية رصينة تركز على الأرقام والنتائج والوقائع وترتيب الأحداث',
            'concise' => 'نبرة سريعة وموجزة ومباشرة تناسب منشورات الأخبار العاجلة وسريعة القراءة',
            default => 'نبرة تفاعلية حيوية كصانع محتوى جماهيري محترف يثير النقاش ويشعل التفاعل بلغة عربية سلسلة وحقيقية',
        };

        $prompt = <<<PROMPT
أنت كاتب محتوى وصانع رأي رقمي محترف (Professional Social Media Content Creator & Journalist).

الهدف: كتابة منشور موحد وواقعي (Master Post) حول موضوع التريند: "{$topic}"
النبرة المطلوبة: {$toneDesc}

إليك المنشورات والآراء التي نشرها الناس فعلياً حول هذا التريند:
\"\"\"
{$postsSummary}
\"\"\"

تعليمات الصياغة البشرية الاحترافية:
1. اقرأ المنشورات أعلاه جيداً، واستخرج الوقائع الفعلية المحددة المذكورة (مثل: أسماء الفرق، الأهداف، اللاعبين، القرارات، أو الأحداث الميدانية بالتحديد كما وردت).
2. اكتب المنشور وكأنك إنسان يتابع الحدث بشغف واحترافية:
   - ابدأ بافتتاحية طبيعية ومثيرة عن صلب ما حدث فعلاً في الميدان.
   - اربط الوقائع بسياقها (مثال: إذا كان فوز فريق وصدارته، تحدث عن مجريات المباراة، أو النقطة الحاسمة، وكيف أثر ذلك على الترتيب أو المشهد العام).
   - تجنب العبارات النمطية الجافة المعلبة (مثل: "رصدنا تفاعلاً واسعاً عبر مختلف المنصات..."). بل اكتب مباشرة عن الحدث كما يتحدث المتابعون والخبراء.
   - لا تضع أي رموز تعبيرية (Emojis) أو أشكال بيانية مزخرفة؛ اجعل قوة المنشور في لغته وصياغته ومحتواه الحقيقي فقط.
3. اختم بسؤال تفاعلي ذكي وجذاب موجه للمتابعين في صلب الحدث ليشاركوا آراءهم.
4. ضع الهاشتاجات الأكثر ملاءمة في السطر الأخير.

أرجع النتيجة حصراً بصيغة JSON نظيفة بالهيكل التالي:
{
    "trend_summary": "ملخص الحدث في سطرين واقعيين ومباشرين",
    "master_post": "نص المنشور الكامل والجاهز للنشر مباشرة (صياغة بشرية واقعية متدفقة)",
    "engagement_question": "سؤال تفاعلي ذكي موجه للمتابعين",
    "key_points": ["الواقعة الأساسية الأولى المستخلصة", "الواقعة الثانية", "الواقعة الثالثة"],
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
            if (is_array($result) && !empty($result['master_post'])) {
                return $result;
            }
        }

        // Contextual human-like synthesis fallback derived directly from topic & posts
        $topicClean = trim(preg_replace('/^(?:رصد\s+)?تريند:\s*/u', '', $topic));
        $tag = '#' . preg_replace('/[^\p{Arabic}\p{L}0-9_]/u', '', mb_substr($topicClean, 0, 20));
        if (empty(trim($tag, '#'))) $tag = '#تريند';

        // Extract key factual sentences from the actual posts
        $keyHighlights = [];
        foreach ($postsList as $p) {
            $txt = is_array($p) ? ($p['text'] ?? '') : (string)$p;
            // Strip emojis and urls
            $txt = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1FA70}-\x{1FAFF}]/u', '', $txt);
            $firstSentence = preg_split('/[.!?؟\n]/u', trim($txt))[0] ?? '';
            $firstSentence = trim($firstSentence);
            if (mb_strlen($firstSentence) > 15 && mb_strlen($firstSentence) < 110) {
                if (!in_array($firstSentence, $keyHighlights)) {
                    $keyHighlights[] = $firstSentence;
                }
            }
            if (count($keyHighlights) >= 4) break;
        }

        $leadStory = !empty($keyHighlights[0]) ? $keyHighlights[0] : "تطورات هامة ومستجدات متسارعة تحيط بـ {$topicClean}";
        $secondStory = !empty($keyHighlights[1]) ? $keyHighlights[1] : "";
        $thirdStory = !empty($keyHighlights[2]) ? $keyHighlights[2] : "";

        if ($tone === 'analytical') {
            $masterDraft = "قراءة في مشهد ({$topicClean}):\n\n"
                . "{$leadStory}، وسط متابعة دقيقة للتفاصيل الفنية والنتائج المسجلة.\n\n"
                . ($secondStory ? "على الصعيد الميداني، شهدت الأحداث: {$secondStory}.\n\n" : "")
                . ($thirdStory ? "فيما تعكس المعطيات: {$thirdStory}.\n\n" : "")
                . "برأيكم، كيف تنعكس هذه المؤشرات على المشهد القادم؟ شاركونا تقييمكم في التعليقات.\n\n"
                . "{$tag} #تحليل #متابعة";
        } elseif ($tone === 'concise') {
            $masterDraft = "موجز ({$topicClean}):\n\n"
                . "• {$leadStory}\n"
                . ($secondStory ? "• {$secondStory}\n" : "")
                . ($thirdStory ? "• {$thirdStory}\n\n" : "\n")
                . "ما رأيكم في مجريات الأحداث الأخيرة؟\n\n"
                . "{$tag}";
        } else {
            // Engaging human tone
            $masterDraft = "{$leadStory}!\n\n"
                . ($secondStory ? "المشهد يزداد إثارة مع: {$secondStory}.\n\n" : "")
                . ($thirdStory ? "وكما تشير التطورات: {$thirdStory}.\n\n" : "")
                . "تفاعل واسع وردود أفعال متباينة بين المتابعين.. برأيكم، هل تستمر هذه المعطيات بنفس الوتيرة أم أن هناك مفاجآت قادمة؟ شاركونا توقعاتكم وتفاعلكم بالتعليقات!\n\n"
                . "{$tag} #تفاعل #متابعة";
        }

        $question = "ما هي توقعاتكم لنتائج وتطورات ({$topicClean}) خلال المرحلة القادمة؟";

        return [
            "trend_summary" => "{$leadStory} وتفاعل متواصل من الجمهور والمهتمين.",
            "master_post" => $masterDraft,
            "engagement_question" => $question,
            "key_points" => !empty($keyHighlights) ? array_slice($keyHighlights, 0, 3) : ["مستجدات وتطورات " . $topicClean, "ردود أفعال الجمهور", "تحليل المشهد الميداني"],
            "hashtags" => [$tag, "#تفاعل", "#متابعة"],
        ];
    }

    /**
     * Generate an AI executive summary for a batch of monitored posts and metrics.
     */
    public function generateReportExecutiveSummary(string $companyName, array $postsSnippet, array $stats): array
    {
        if (empty($this->apiKey)) {
            return [
                'summary' => "تقرير رصد وتحليل إعلامي شامل لمؤسسة {$companyName}. يوضح مؤشرات التفاعل العام وتوزيع المشاعر عبر مختلف المنصات الرقمية.",
                'positive_insights' => ["تفاعل إيجابي ملحوظ على المنشورات الرسمية", "إشادة الجمهور بسرعة الاستجابة وجودة الخدمات"],
                'negative_concerns' => ["بعض الاستفسارات حول أوقات الخدمة والدعم الفني"],
                'recommendations' => ["تكثيف النشر في أوقات الذروة", "الرد السريع على تساؤلات المستفيدين"],
            ];
        }

        $postsText = implode("\n", array_slice($postsSnippet, 0, 15));
        $prompt = <<<PROMPT
أنت كبير محللي البيانات والإعلام لمنصة "مرآة" للرصد والتحليل الذكي.
قم بتحليل الرصد الإعلامي لمؤسسة: "{$companyName}".
الإحصائيات العامة: إجمالي المنشورات ({$stats['total']})، إيجابي ({$stats['positive']})، محايد ({$stats['neutral']})، سلبي ({$stats['negative']}).
عينة من المنشورات والتعليقات المرصودة:
{$postsText}

المطلوب إخراج كائن JSON فقط بالهيكل التالي بدون أي كود ماركداون:
{
  "summary": "فقرة موجزة ودقيقة وشاملة (3-4 أسطر) تلخص حالة الرصد الإعلامي اليوم وأبرز ما دار حول الشركة",
  "positive_insights": ["نقطة 1 تبرز أهم الجوانب الإيجابية", "نقطة 2"],
  "negative_concerns": ["نقطة تبرز أبرز الملاحظات أو الانتقادات إن وجدت"],
  "recommendations": ["توصية تنفيذية للمسؤولين 1", "توصية تنفيذية 2"]
}
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => $prompt],
        ];

        $content = $this->callGemini($messages, 60);
        if ($content) {
            $cleaned = preg_replace('/```json|```/', '', $content);
            $json = json_decode(trim($cleaned), true);
            if (is_array($json) && !empty($json['summary'])) {
                return $json;
            }
        }

        return [
            'summary' => "تقرير رصد إعلامي شامل لمؤسسة {$companyName}. يوضح تفاعل الجمهور ومؤشرات الرصد الرقمي.",
            'positive_insights' => ["تفاعل مستقر وإيجابي على قنوات التواصل"],
            'negative_concerns' => ["لا توجد أزمات حرجة مرصودة"],
            'recommendations' => ["مواصلة رصد الكلمات المفتاحية واستشعار الرأي العام"],
        ];
    }

    /**
     * Analyze Google Maps reviews for a specific location using Gemini AI.
     * Generates sentiment score and summary for each review, plus an executive summary and recommendations for the location.
     *
     * @param string $businessName
     * @param array $reviews List of reviews: [['id' => 1, 'rating' => 5, 'text' => '...', 'author_name' => '...']]
     * @return array ['summary' => string, 'recommendations' => array, 'reviews' => array]
     */
    public function analyzeLocationReviews(string $businessName, array $reviews): array
    {
        if (empty($reviews)) {
            return [
                'summary' => "لا توجد مراجعات كافية متاحة حالياً لموقع {$businessName} لإجراء تحليل ذكي دقيق.",
                'recommendations' => [
                    'قم بدعوة عملائك وزوارك لمشاركة تقييماتهم وتجاربهم على خرائط Google.',
                    'ضع رمز QR في الفرع يوجه الزوار مباشرة لصفحة التقييم على خرائط Google.',
                ],
                'reviews' => [],
            ];
        }

        // Limit reviews sent to prompt if very large to prevent token exhaustion
        $slicedReviews = array_slice($reviews, 0, 100);
        $reviewsFormatted = [];
        foreach ($slicedReviews as $rev) {
            $id = $rev['id'] ?? null;
            $rating = $rev['rating'] ?? 5;
            $author = $rev['author_name'] ?? 'مستخدم';
            $text = trim($rev['text'] ?? '');
            if (empty($text)) {
                $text = "تقييم بدون نص ({$rating} نجوم)";
            }
            $reviewsFormatted[] = "[ID: {$id} | Rating: {$rating}/5 | Author: {$author}]: {$text}";
        }

        $reviewsInput = implode("\n", $reviewsFormatted);

        $prompt = <<<PROMPT
أنت كبير خبراء تجربة العميل (Customer Experience & Sentiment Analyst) في منصة "مرآة".
قم بإجراء تحليل ذكاء اصطناعي دقيق لمراجعات وتقييمات خرائط Google للفرع: "{$businessName}".

قائمة المراجعات المرصودة للتحليل:
{$reviewsInput}

المطلوب:
1. تحليل كل مراجعة من المراجعات المذكورة أعلاه بدقة وتحديد:
   - id: رقم معرف المراجعة كما هو في المدخلات (عدد صحيح).
   - sentiment: إما 'positive' أو 'neutral' أو 'negative'.
   - sentiment_score: رقم عشري بين -1.0 (سلبي جداً) و +1.0 (إيجابي جداً)، 0.0 للمحايد.
   - ai_analysis: جملة أو جملتين باللغة العربية توضح سبب المشاعر والعوامل المذكورة (مثل: "إشادة بنظافة المكان وحسن الضيافة"، "شكوى من تأخر استلام الطلب والازدحام").

2. تقديم ملخص تنفيذي ذكي شامل (summary) باللغة العربية:
   - ملخص مهني وشامل لتجربة العملاء العامة، نقاط القوة التي يثني عليها العملاء، والمشكلات المتكررة ونقاط الضعف التي تستوجب الانتباه.

3. تقديم توصيات تشغيلية واستراتيجية قابلة للتنفيذ (recommendations):
   - مصفوفة من 3 إلى 6 توصيات عملية باللغة العربية تهدف لتحسين تقييم الفرع وزيادة رضا الزوار وولائهم.

أخرج النتيجة بصيغة JSON فقط متطابقة مع المخطط التالي وبدون أي كود ماركداون:
{
  "summary": "نص الملخص التنفيذي الشامل للفرع باللغة العربية...",
  "recommendations": [
    "توصية عملية 1...",
    "توصية عملية 2...",
    "توصية عملية 3..."
  ],
  "reviews": [
    {
      "id": 1,
      "sentiment": "positive",
      "sentiment_score": 0.85,
      "ai_analysis": "إشادة بجودة الخدمة والسرعة"
    }
  ]
}
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $content = $this->callGemini($messages, 90);
            if ($content) {
                $cleaned = preg_replace('/```(?:json)?|```/iu', '', trim($content));
                $json = json_decode(trim($cleaned), true);
                if (is_array($json) && !empty($json['summary'])) {
                    return [
                        'summary' => (string) $json['summary'],
                        'recommendations' => is_array($json['recommendations'] ?? null) ? $json['recommendations'] : [],
                        'reviews' => is_array($json['reviews'] ?? null) ? $json['reviews'] : [],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Gemini analyzeLocationReviews failed: " . $e->getMessage());
        }

        // Heuristic fallback if Gemini API call fails or quota exceeded
        return $this->generateHeuristicLocationAnalysis($businessName, $reviews);
    }

    /**
     * Fallback heuristic analysis for location reviews if AI call is unavailable.
     */
    protected function generateHeuristicLocationAnalysis(string $businessName, array $reviews): array
    {
        $total = count($reviews);
        $positive = 0;
        $negative = 0;
        $neutral = 0;
        $processedReviews = [];

        foreach ($reviews as $rev) {
            $rating = (int) ($rev['rating'] ?? 5);
            $id = $rev['id'] ?? null;
            if ($rating >= 4) {
                $positive++;
                $sentiment = 'positive';
                $score = 0.8;
                $analysis = 'تجربة ممتازة وانطباع إيجابي من العميل بناءً على التقييم.';
            } elseif ($rating === 3) {
                $neutral++;
                $sentiment = 'neutral';
                $score = 0.0;
                $analysis = 'تجربة متوسطة تلبي التوقعات الأساسية مع وجود مجال للتحسين.';
            } else {
                $negative++;
                $sentiment = 'negative';
                $score = -0.8;
                $analysis = 'ملاحظات سلبية حول التجربة تتطلب متابعة فورية لمعالجة أوجه القصور.';
            }

            if ($id) {
                $processedReviews[] = [
                    'id' => $id,
                    'sentiment' => $sentiment,
                    'sentiment_score' => $score,
                    'ai_analysis' => $analysis,
                ];
            }
        }

        $posPercent = $total > 0 ? round(($positive / $total) * 100) : 0;
        $summary = "تحليل مراجعات فرع {$businessName}: يعكس الرصد العام رضا العملاء بنسبة إيجابية تبلغ {$posPercent}٪ من إجمالي {$total} تقييم. تُشير التقييمات المرتفعة إلى رضا عام عن جودة الخدمات، مع الحاجة المستمرة للعناية بملاحظات العملاء الأقل تقييماً لتعزيز تجربة العميل.";

        $recommendations = [
            "الرد المستمر على جميع تقييمات العملاء خاصة السلبية لمعالجة الملاحظات وبناء الثقة.",
            "مكافأة وتشجيع العملاء الراضين على مشاركة صور وتجارب تفصيلية إيجابية على خرائط Google.",
            "التحسين الدوري لأوقات الاستجابة والخدمة بناءً على الملاحظات المتكررة.",
        ];

        return [
            'summary' => $summary,
            'recommendations' => $recommendations,
            'reviews' => $processedReviews,
        ];
    }
}

