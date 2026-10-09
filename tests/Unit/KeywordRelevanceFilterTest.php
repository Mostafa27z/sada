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

    public function test_targeted_search_queries_contains_no_duplicate_or_redundant_items(): void
    {
        $keywords = ['شركة سدا للحلول', 'سدا للحلول', 'شركة سدا للحلول'];
        $queries = KeywordRelevanceFilter::generateTargetedSearchQueries($keywords);

        // Assert all elements are unique
        $this->assertEquals(count($queries), count(array_unique($queries)));

        // Assert no element exists in both quoted and unquoted form
        foreach ($queries as $q) {
            $unquoted = trim($q, '"');
            $quoted = "\"{$unquoted}\"";
            if ($q === $quoted) {
                $this->assertNotContains($unquoted, $queries, "Queries should not contain both {$quoted} and {$unquoted}");
            }
        }
    }

    public function test_patronymic_person_entity_disambiguation_and_sports_noise_rejection(): void
    {
        $keywords = ['مجمع تبوك الطبي', 'متعب بن عبدالله الحربي'];

        $footballPosts = [
            'بعد تحقيق كأس الخليج.. متعب الحربي يستمتع بوقته في لعبة FC27 😂😂💚💚💚',
            'نصائح من محمد نور لمتعب الحربي 💚 #السعودية #الرياضة_على_تيك_توك #explore',
            'متعب الحربي : هذا اقل شي نقدمه للجمهور السعودي . #السعودية #الرياضة_على_تيك_توك',
            'متعب الحربي: هذي قناة الكاس؟ سبيت خاطر شلونك؟ حبيت اسلم عليه واقول شفت السعودية؟ #الأهلي #الملكي',
            'متعب الحربي : نوعد الجمهور بتحقيق آسيا بإذن الله💚 #خليجي27 #متعب_الحربي #السعودية',
            'لقاء لاعب المنتخب السعودي متعب الحربي مع موفد القناة الرياضية ماهر العنزي #الكويت #الرياضية',
            'صحيفة الرياضية: متعب الحربي لاعب المنتخب السعودي الأول لكرة القدم لـ الرياضية: بطولة مستحقة.. قدمنا مستوى ممتازًا',
            'متعب الحربي: شكرًا للجمهور ونوعدهم بالأهم؛ كأس آسيا #الأهلي #الملكي',
            'متعب الحربي لاعب الهلال يتألق في ديربي الرياض ويصنع هدف الفوز',
        ];

        foreach ($footballPosts as $post) {
            $this->assertFalse(
                KeywordRelevanceFilter::isContentRelevant($post, $keywords),
                "Failed asserting that football post is rejected: {$post}"
            );
        }

        $legitimateEntityPosts = [
            'مجمع تبوك الطبي ينظم حملة توعوية موسعة للكشف المبكر عن السكري وضغط الدم',
            'تجمع تبوك الصحي يعلن بدء تشغيل العيادات المسائية بمجمع تبوك لخدمة المراجعين',
            'قام الأستاذ متعب بن عبدالله الحربي بجولة تفقدية لمرافق مجمع تبوك الطبي للاطلاع على سير العمل',
            'رئيس مجمع تبوك الطبي متعب الحربي يشيد بجهود الكادر الطبي والتمريضي في خدمة المرضى',
            'تكليف الأستاذ متعب بن عبدالله الحربي رئيساً لمجمع تبوك الطبي وتطوير الخدمات الصحية',
            'صرح الرئيس التنفيذي متعب الحربي بأن مجمع تبوك الطبي يشهد نقلة نوعية في التجهيزات',
        ];

        foreach ($legitimateEntityPosts as $post) {
            $this->assertTrue(
                KeywordRelevanceFilter::isContentRelevant($post, $keywords),
                "Failed asserting that healthcare entity post is accepted: {$post}"
            );
        }
    }

    public function test_balanced_round_robin_query_generation_for_multiple_keywords(): void
    {
        $keywords = ['مجمع تبوك الطبي', 'متعب بن عبدالله الحربي'];
        $queries = KeywordRelevanceFilter::generateTargetedSearchQueries($keywords);

        // 1. Must NOT contain bare ambiguous footballer name
        $this->assertNotContains('"متعب الحربي"', $queries);
        $this->assertNotContains('متعب الحربي', $queries);
        $this->assertNotContains('"متعب" "الحربي"', $queries);

        // 2. Must contain full patronymic queries
        $this->assertContains('"متعب بن عبدالله الحربي"', $queries);

        // 3. Must contain contextually anchored queries with healthcare/location tokens
        $hasContextualAnchor = false;
        foreach ($queries as $q) {
            if (str_contains($q, 'متعب الحربي') && (str_contains($q, 'تبوك') || str_contains($q, 'طبي') || str_contains($q, 'صحي') || str_contains($q, 'تنفيذي') || str_contains($q, 'رئيس'))) {
                $hasContextualAnchor = true;
                break;
            }
        }
        $this->assertTrue($hasContextualAnchor, 'Queries should contain contextually anchored pairs for the person');

        // 4. Must contain the primary organization keyword in the top slots
        $top4 = array_slice($queries, 0, 4);
        $hasOrgInTop = false;
        $hasPersonInTop = false;
        foreach ($top4 as $q) {
            if (str_contains($q, 'مجمع تبوك') || str_contains($q, 'تجمع تبوك')) {
                $hasOrgInTop = true;
            }
            if (str_contains($q, 'متعب')) {
                $hasPersonInTop = true;
            }
        }
        $this->assertTrue($hasOrgInTop, 'Top queries must include organization keyword (fair allocation)');
        $this->assertTrue($hasPersonInTop, 'Top queries must include person keyword (fair allocation)');
    }
}

