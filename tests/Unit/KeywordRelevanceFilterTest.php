<?php

namespace Tests\Unit;

use App\Support\KeywordRelevanceFilter;
use PHPUnit\Framework\TestCase;

class KeywordRelevanceFilterTest extends TestCase
{
    public function test_irrelevant_posts_are_strictly_rejected(): void
    {
        $keywords = ['متعب بن عبد الله الحربي رئيس مجمع تبوك الصحي'];

        $irrelevantPosts = [
            'سمو #أمير_منطقة_القصيم يستقبل مدير فرع وزارة الصحة والرئيس التنفيذي لتجمع القصيم الصحي ويطلع على خطة تنفيذ #حملة_ولي_العهد_للتبرع_بالدم في المنطقة',
            'قيمة الاجتماع لا تُقاس بالصورة، بل بما يتركه من نتائج.. فخور بلقاء الدكتور/ أمير التلواني - المدير التنفيذي للهيئة العامة للرعاية الصحية',
            'جهود مباركة وبصمات واضحة.. رئيس مركز ساجر الشيخ نايف بن متعب بن محيا.. دور بارز في دعم التنمية الشاملة',
            'مقدار افرط فيهم ههههههههه #اكسبلور #explore #تبوك',
            'رئيس مركز ساجر #ساجر #المحيا #ساجر',
            '#اكسبلور explore# #تبوك_الان #الشعب_الصيني_fyp #البدع_حقل_ضباء_تبوك_نيوم',
            'طلاب و خريجي مدارس الملك عبدالعزيز النموذجية بتبوك',
            'متعب الحربي يسجل هدف الفوز لنادي الهلال اليوم في دوري روشن #الهلال #متعب_الحربي',
            'رسميا نادي الهلال يتعاقد مع الظهير متعب الحربي قادما من نادي الشباب في صفقة تاريخية',
            'تجمع تبوك الصحي يدشن خدمة جديدة للمستفيدين في المنطقة',
            'مجمع تبوك الصحي يعلن عن بدء تشغيل العيادات المسائية',
        ];

        foreach ($irrelevantPosts as $post) {
            $this->assertFalse(
                KeywordRelevanceFilter::isContentRelevant($post, $keywords),
                "Failed asserting that post is rejected: {$post}"
            );
        }
    }

    public function test_relevant_posts_are_accepted(): void
    {
        $keywords = ['متعب بن عبد الله الحربي رئيس مجمع تبوك الصحي'];

        $relevantPosts = [
            'استقبل الأستاذ متعب الحربي رئيس تجمع تبوك الصحي وفداً رفيع المستوى بمقر التجمع',
            'متعب بن عبدالله الحربي يتفقد سير العمل في مجمع تبوك الصحي ويشيد بجهود الكادر الطبي',
            'صرح الرئيس التنفيذي لتجمع تبوك الصحي متعب الحربي بأن المشاريع تسير وفق الخطة',
            'قام الأستاذ متعب الحربي بزيارة تفقدية للقطاع الصحي في تبوك',
        ];

        foreach ($relevantPosts as $post) {
            $this->assertTrue(
                KeywordRelevanceFilter::isContentRelevant($post, $keywords),
                "Failed asserting that post is accepted: {$post}"
            );
        }
    }

    public function test_targeted_search_queries_generation(): void
    {
        $keywords = ['متعب بن عبد الله الحربي رئيس مجمع تبوك الصحي'];
        $queries = KeywordRelevanceFilter::generateTargetedSearchQueries($keywords);

        $this->assertContains('"متعب الحربي" "تجمع تبوك الصحي"', $queries);
        $this->assertContains('"متعب الحربي" "تبوك الصحي"', $queries);
        $this->assertContains('"متعب الحربي" "تبوك"', $queries);
        $this->assertContains('"متعب بن عبد الله الحربي"', $queries);
        $this->assertNotContains('"متعب الحربي"', $queries);
    }

    public function test_ibn_and_short_queries_are_accepted(): void
    {
        $post = 'تكليف متعب الحربي رئيساً تنفيذياً لتجمع تبوك الصحي';

        $variations = [
            'متعب بن عبدالله الحربي تبوك الصحي',
            'متعب ابن عبدالله الحربي تبوك الصحي',
            'متعب بن عبدالله الحربي',
            'متعب ابن عبدالله الحربي',
            'متعب الحربي',
            'متعب بن عبدالله الحربي الرئيس التنفيذي لتجمع تبوك الصحي',
        ];

        foreach ($variations as $kw) {
            $this->assertTrue(
                KeywordRelevanceFilter::isContentRelevant($post, [$kw]),
                "Failed asserting that post is relevant for keyword: {$kw}"
            );
        }
    }

    public function test_compound_and_plus_queries_handling(): void
    {
        $postTarget = 'صرح متعب الحربي الرئيس التنفيذي لتجمع تبوك الصحي بأن الخطة تسير بنجاح';
        $postFootball = 'هدف عالمي من متعب الحربي لاعب الهلال في شباك النصر';

        // 1. Plus operator
        $kwPlus = ['متعب الحربي + تجمع تبوك الصحي'];
        $this->assertTrue(KeywordRelevanceFilter::isContentRelevant($postTarget, $kwPlus));
        $this->assertFalse(KeywordRelevanceFilter::isContentRelevant($postFootball, $kwPlus));

        // 2. Compound natural phrase without plus
        $kwCompound = ['متعب الحربي تجمع تبوك الصحي'];
        $this->assertTrue(KeywordRelevanceFilter::isContentRelevant($postTarget, $kwCompound));
        $this->assertFalse(KeywordRelevanceFilter::isContentRelevant($postFootball, $kwCompound));
    }
}

