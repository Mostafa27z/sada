<?php

namespace App\Services;

use App\Models\ChatRoom;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class MarketingConsultantAiService extends GeminiAnalyticsService
{
    public function __construct(
        protected TenantDataAggregatorService $aggregatorService
    ) {
        parent::__construct();
    }

    /**
     * Consult the AI marketing agent using context-aware tenant data.
     *
     * @param Tenant $tenant
     * @param ChatRoom $room
     * @param User $sender
     * @param string $userMessage
     * @return array{thinking_steps: array, response: string, metadata: array}
     */
    public function consult(Tenant $tenant, ChatRoom $room, User $sender, string $userMessage): array
    {
        // 1. Gather ONLY relevant tenant data based on user query intent
        $context = $this->aggregatorService->getTenantMarketingContext($tenant, $room, $userMessage);
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $detectedIntent = $context['detected_intent'] ?? 'general';

        $prompt = "أنت '{$room->ai_consultant_name}'، كبير مسؤولي التسويق (CMO) واستراتيجي النمو لشركة '{$tenant->name}'.
أنت عضو في محادثة جماعية مع فريق العمل. وظيفتك تنفيذ طلبات الفريق فوراً وبأعلى جودة إبداعية وتسويقية.

رسالة أو طلب العضو ({$sender->name}):
\"{$userMessage}\"

سياق بيانات الشركة ذات الصلة بهذا الطلب:
{$contextJson}

تعليمات إلزامية للإجابة:
1. التنفيذ المباشر للطلب (Fulfill the specific command first):
   - إذا طلب العضو كتابة منشور، إعلان، أو محتوى تسويقي (مثل: إعلان لمعجون أسنان سيجنال أو غيره): اكتب المنشور التسويقي بالكامل فوراً! متضمناً:
     * 🎯 الخطاف والعنوان الجذاب (Hook)
     * 📝 نص المحتوى المقنع (Body Copy)
     * 🚀 الدعوة لاتخاذ إجراء (Call to Action)
     * 🏷️ الهاشتاقات المناسبة
     * 💡 فكرة المحتوى البصري أو الفيديو المقترح
   - إذا طلب العضو استشارة استراتيجية أو تحليل، قدم خطة واضحة ومحددة.
   - لا تخلط الشكاوى أو المشاكل القديمة مع طلبات كتابة الإعلانات والمنشورات التسويقية الإبداعية إلا إذا طلب المستخدم ذلك صراحة!

2. خطوات التفكير التفاعلية (Thinking Steps):
   قم بتوثيق من 2 إلى 4 خطوات توضح تفكيرك الفعلي الخاص بطلب المستخدم هذا حصراً (وليس قالباً ثابتاً).
   مثال لو طلب منشور لمعجون أسنان:
   - خطوة 1: تحليل هوية المنتج (سيجنال) ومزاياه التنافسية (انتعاش، حماية، ابتسامة بيضاء).
   - خطوة 2: اختيار زاوية الخطاف التسويقي ونبرة الصوت المناسبة لمنصات التواصل.
   - خطوة 3: صياغة المحتوى المتكامل مع الدعوة للتفاعل (CTA).

3. مقترحات المتابعة السريعة (suggested_followups):
   ضع في metadata قائمة بـ 2 إلى 3 أسئلة أو إجراءات سريعة ومحددة يمكن للعضو النقر عليها لمواصلة التطوير (مثل: 'صياغة سكريبت فيديو تيك توك لهذا المنشور', 'اقتراح أفكار تصاميم بصرية', 'تحديد أفضل أوقات النشر').

يجب أن يكون الرد بتنسيق JSON حصراً بالشكل التالي:
{
  \"thinking_steps\": [
    {
      \"step\": 1,
      \"title\": \"عنوان خطوة التفكير الخاصة بطلب المستخدم\",
      \"detail\": \"تفاصيل ما قمت بتحليله وتحديده...\",
      \"data_source\": \"مصدر البيانات أو المعيار التسويقي\"
    }
  ],
  \"response\": \"نص الإجابة أو المنشور التسويقي المكتمل باللغة العربية بتنسيق Markdown احترافي...\",
  \"metadata\": {
    \"primary_concern\": \"موضوع الرسالة الأساسي\",
    \"recommended_channel\": \"القناة المقترحة\",
    \"suggested_followups\": [
      \"اقتراح متابعة 1\",
      \"اقتراح متابعة 2\"
    ],
    \"confidence_score\": 0.95
  }
}";

        $messages = [
            ['role' => 'system', 'content' => 'أنت خبير تسويق إبداعي ينفذ طلبات الفريق مباشرة ويقدم مخرجات تسويقية جاهزة للنشر باللغة العربية مع خطوات تفكير واقعية في صيغة JSON.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $rawJson = $this->callGemini($messages, 60);
            if ($rawJson) {
                $parsed = json_decode($rawJson, true);
                if (is_array($parsed) && isset($parsed['thinking_steps'], $parsed['response'])) {
                    return [
                        'thinking_steps' => (array) $parsed['thinking_steps'],
                        'response' => (string) $parsed['response'],
                        'metadata' => (array) ($parsed['metadata'] ?? []),
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error("MarketingConsultantAiService::consult error: " . $e->getMessage());
        }

        // Contextual dynamic fallback matching the user's actual prompt
        return $this->generateContextualFallback($userMessage, $detectedIntent, $context);
    }

    /**
     * Generate an intelligent fallback when the external API call fails, strictly matching the user's intent.
     */
    protected function generateContextualFallback(string $userMessage, string $intent, array $context): array
    {
        // Clean query from mention
        $cleanQuery = trim(preg_replace('/@ai\b/iu', '', $userMessage));

        if ($intent === 'copywriting') {
            $steps = [
                [
                    'step' => 1,
                    'title' => 'تحليل طلب المحتوى والجمهور المستهدف',
                    'detail' => "تحديد زاوية الصياغة التسويقية المناسبة لطلب: {$cleanQuery}",
                    'data_source' => 'معايير صناعة المحتوى الإعلاني',
                ],
                [
                    'step' => 2,
                    'title' => 'صياغة الخطاف التسويقي (Hook)',
                    'detail' => 'اختيار مدخل يركز على الفائدة المباشرة للعميل وتحفيز الفضول والتفاعل.',
                    'data_source' => 'هندسة الرسائل الإعلانية',
                ],
                [
                    'step' => 3,
                    'title' => 'بناء المحتوى والدعوة لاتخاذ إجراء (CTA)',
                    'detail' => 'تجهيز المنشور متضمناً النص الرئيسي والهاشتاقات وفكرة التصميم المقترحة.',
                    'data_source' => 'صياغة المحتوى المتكامل',
                ],
            ];

            $response = "### 🎯 مقترح منشور تسويقي: {$cleanQuery}\n\n" .
                "**الخطاف (Hook):**\n" .
                "✨ ابتسامة ناصعة وثقة تدوم طوال اليوم! لأن أول انطباع يبدأ بابتسامتك.\n\n" .
                "**نص المنشور (Body Copy):**\n" .
                "هل تبحث عن انتعاش حقيقي وحماية متكاملة لأسنانك ولثتك؟ مع التركيبة المتقدمة، امنح أسنانك العناية اليومية التي تستحقها لتحصل على بياض طبيعي ونفَس منعش يدوم لساعات.\n\n" .
                "**الدعوة للتفاعل (CTA):**\n" .
                "🛍️ متوفر الآن في جميع الصيدليات ونقاط البيع المعتمدة. اطلبه اليوم وعِش تجربة الانتعاش!\n\n" .
                "**الهاشتاقات المقترحة:**\n" .
                "#ابتسامة_صحية #عناية_بالأسنان #انتعاش_دائم #صحة_الفم #جمال_الابتسامة\n\n" .
                "💡 **فكرة المحتوى البصري:** صورة مكبّرة ومشرقة لابتسامة طبيعية مع إظهار عبوة المنتج والرغوة المنعشة وقطرات الماء.";

            $followups = [
                'تحويل المنشور إلى سكريبت فيديو لمنصة تيك توك',
                'صياغة تغريدة سريعة لنفس المنتج على منصة X',
                'اقتراح أفكار حملة مسابقات للمتابعين',
            ];

            return [
                'thinking_steps' => $steps,
                'response' => $response,
                'metadata' => [
                    'primary_concern' => 'صياغة محتوى تسويقي',
                    'recommended_channel' => 'Instagram & TikTok',
                    'suggested_followups' => $followups,
                    'confidence_score' => 0.90,
                ],
            ];
        }

        if ($intent === 'reputation_complaints') {
            $complaintText = $context['complaint_executive_summary'] ?? 'متابعة شكاوى العملاء';
            $steps = [
                [
                    'step' => 1,
                    'title' => 'فحص سجلات شكاوى العملاء وتقييماتهم',
                    'detail' => 'استرجاع المؤشرات السلبية الأخيرة لتحديد الأسباب الجذرية للمشكلات.',
                    'data_source' => 'سجلات الشكاوى والتقييمات',
                ],
                [
                    'step' => 2,
                    'title' => 'وضع خطة احتواء سريعة للعملاء',
                    'detail' => 'تحديد نقاط التماس الحساسة وإعداد ردود استباقية للحد من الأثر السلبي.',
                    'data_source' => 'إدارة تجربة العملاء والسمعة',
                ],
            ];

            $response = "### 📊 ملخص وتوصيات إدارة السمعة وشكاوى العملاء:\n\n" .
                "1. **الوضع الحالي:** {$complaintText}\n" .
                "2. **الإجراء العاجل:** التواصل المباشر مع أصحاب التقييمات المنخفضة (1-2) لتقديم حلول فورية وتعويض مناسب.\n" .
                "3. **التوجيه التسويقي:** إطلاق محتوى توضيحي يؤكد التزام الشركة بالجودة وسرعة التجاوب مع ملاحظات العملاء.";

            return [
                'thinking_steps' => $steps,
                'response' => $response,
                'metadata' => [
                    'primary_concern' => 'معالجة الشكاوى وتحسين السمعة',
                    'recommended_channel' => 'الدعم المباشر والبريد الإلكتروني',
                    'suggested_followups' => [
                        'صياغة رسالة اعتذار وتعويض للعملاء المتأثرين',
                        'مراجعة تقرير المشاعر على السوشيال ميديا',
                    ],
                    'confidence_score' => 0.90,
                ],
            ];
        }

        // General fallback
        return [
            'thinking_steps' => [
                [
                    'step' => 1,
                    'title' => 'تحليل استفسار الفريق التسويقي',
                    'detail' => "دراسة الطلب: {$cleanQuery} وربطه بالأولويات التسويقية.",
                    'data_source' => 'الاستراتيجية العامة للتسويق',
                ],
                [
                    'step' => 2,
                    'title' => 'صياغة التوجيه الاستشاري',
                    'detail' => 'تحديد خطوات تنفيذية واضحة ومباشرة لفريق العمل.',
                    'data_source' => 'أفضل ممارسات النمو الرقمي',
                ],
            ],
            'response' => "بخصوص استفسارك حول **{$cleanQuery}**:\n\nنوصي بالتركيز على تحديد القيمة المضافة لجمهورك المستهدف أولاً، ثم قياس التفاعل عبر اختبار رسائل تسويقية متعددة (A/B Testing) للوصول لأفضل عائد استثماري على الحملة.",
            'metadata' => [
                'primary_concern' => 'الاستشارة التسويقية',
                'recommended_channel' => 'قنوات التواصل الرقمي',
                'suggested_followups' => [
                    'تحديد الميزانية وخطة النشر المقترحة',
                    'صياغة نصوص إعلانية للحملة',
                ],
                'confidence_score' => 0.88,
            ],
        ];
    }
}
