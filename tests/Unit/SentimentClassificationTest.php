<?php

namespace Tests\Unit;

use App\Services\AiScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SentimentClassificationTest extends TestCase
{
    public function test_leadership_appointments_and_promotions_are_classified_as_positive(): void
    {
        $service = app(AiScraperService::class);
        $keywords = ['متعب بن عبدالله الحربي'];

        $positivePosts = [
            'تكليف متعب الحربي رئيساً تنفيذياً لتجمع تبوك الصحي',
            'تعيين الأستاذ متعب بن عبدالله الحربي (رئيساً تنفيذياً لتجمع تبوك الصحي)',
            '#عاجل بقرار من وزير الصحة تكليف الأستاذ متعب بن عبدالله الحربي رئيساً تنفيذيا لتجمع تبوك الصحي',
            'نبارك للأستاذ متعب الحربي نيل الثقة بتكليفه رئيساً تنفيذياً لتجمع تبوك الصحي',
            'ترقية وتكليف متعب الحربي مديراً عاماً لتجمع تبوك الصحي',
            'تكريم الأستاذ متعب الحربي لإنجازاته المتميزة في تجمع تبوك الصحي',
        ];

        foreach ($positivePosts as $post) {
            $result = $service->classifyArabicSentiment($post, null, $keywords);
            $this->assertEquals(
                'positive',
                $result['sentiment'],
                "Failed asserting that post is classified as positive: {$post}"
            );
            $this->assertGreaterThanOrEqual(0.85, $result['score']);
        }
    }

    public function test_dismissals_complaints_and_violations_are_classified_as_negative(): void
    {
        $service = app(AiScraperService::class);
        $keywords = ['متعب بن عبدالله الحربي'];

        $negativePosts = [
            'إعفاء متعب الحربي من منصبه وتحويله للتحقيق بسبب إهمال',
            'إنهاء تكليف متعب الحربي بعد شكاوى متكررة من المستفيدين',
            'سوء إدارة وتراجع كبير في خدمات تجمع تبوك الصحي ومطالبات بإقالة الإدارة',
            'شكوى رسمية ضد تجمع تبوك الصحي بسبب تدني مستوى الخدمة',
        ];

        foreach ($negativePosts as $post) {
            $result = $service->classifyArabicSentiment($post, null, $keywords);
            $this->assertEquals(
                'negative',
                $result['sentiment'],
                "Failed asserting that post is classified as negative: {$post}"
            );
            $this->assertGreaterThanOrEqual(0.85, $result['score']);
        }
    }

    public function test_procedural_inquiries_are_classified_as_neutral(): void
    {
        $service = app(AiScraperService::class);
        $keywords = ['متعب بن عبدالله الحربي'];

        $neutralPosts = [
            'ما هي مواعيد الدوام وأوقات العمل في العيادات؟',
            'رقم التواصل وموقع مركز تجمع تبوك الصحي',
        ];

        foreach ($neutralPosts as $post) {
            $result = $service->classifyArabicSentiment($post, null, $keywords);
            $this->assertEquals(
                'neutral',
                $result['sentiment'],
                "Failed asserting that post is classified as neutral: {$post}"
            );
        }
    }
}
