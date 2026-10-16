<?php

namespace Tests\Feature\Monitoring;

use App\Models\Collection;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiScraperService;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PlatformLimitsAndScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_contains_daily_sync_all_and_no_scrape_keywords(): void
    {
        $schedule = app()->make(Schedule::class);
        $events = collect($schedule->events());

        $hasSyncAll = $events->contains(function ($event) {
            return str_contains($event->command, 'sada:sync-all') && $event->expression === '0 0 * * *';
        });

        $hasScrapeKeywords = $events->contains(function ($event) {
            return str_contains($event->command, 'sada:scrape-keywords');
        });

        $this->assertTrue($hasSyncAll, 'Schedule should contain sada:sync-all running daily at 00:00 (0 0 * * *)');
        $this->assertFalse($hasScrapeKeywords, 'Schedule should NOT contain sada:scrape-keywords anymore');
    }

    public function test_collection_creation_stores_and_clamps_platform_limits_for_trial_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Trial Corp',
            'slug' => 'trial-corp',
            'status' => Tenant::STATUS_TRIAL,
            'is_active' => true,
            'trial_ends_at' => now()->addDays(5),
        ]);

        $user = User::factory()->create();
        $tenant->users()->attach($user, ['is_owner' => true]);

        TenantContext::setTenant($tenant);

        $this->mock(AiScraperService::class, function (MockInterface $mock) {
            $mock->shouldReceive('scrapeByKeywords')
                ->andReturn([
                    'status' => 'success',
                    'posts_by_platform' => [],
                ]);
        });

        // Request 30 for X and 20 for Instagram = 50 total (Exceeds trial max of 25)
        $response = $this->actingAs($user)->postJson('/api/v1/collections', [
            'name' => 'Trial Campaign',
            'keywords' => ['Health'],
            'platforms' => ['x', 'instagram'],
            'platform_limits' => [
                'x' => 30,
                'instagram' => 20,
            ],
            'country' => 'SA',
        ]);

        $response->assertStatus(201);
        $json = $response->json('data');

        // Total should be clamped to 25 (the trial limit)
        $this->assertEquals(25, (int) $json['comments_limit']);
        $this->assertNotNull($json['platform_limits']);
        $totalPlatformLimits = array_sum($json['platform_limits']);
        $this->assertEquals(25, $totalPlatformLimits);

        TenantContext::forgetTenant();
    }

    public function test_collection_creation_respects_plan_max_articles_per_campaign(): void
    {
        $plan = Plan::create([
            'name' => 'Basic Plan',
            'slug' => 'basic-plan',
            'price' => 349,
            'currency' => 'SAR',
            'billing_interval' => 'monthly',
            'max_campaigns' => 10,
            'max_articles_per_campaign' => 100,
            'max_articles' => 3000,
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'name' => 'Subscribed Corp',
            'slug' => 'sub-corp',
            'status' => Tenant::STATUS_ACTIVE,
            'is_active' => true,
            'plan_id' => $plan->id,
        ]);

        $user = User::factory()->create();
        $tenant->users()->attach($user, ['is_owner' => true]);

        TenantContext::setTenant($tenant);

        $this->mock(AiScraperService::class, function (MockInterface $mock) {
            $mock->shouldReceive('scrapeByKeywords')
                ->andReturn([
                    'status' => 'success',
                    'posts_by_platform' => [],
                ]);
        });

        // Request 50 for X and 30 for Instagram = 80 total (Within Basic plan cap of 100)
        $response = $this->actingAs($user)->postJson('/api/v1/collections', [
            'name' => 'Subscribed Campaign',
            'keywords' => ['Technology'],
            'platforms' => ['x', 'instagram'],
            'platform_limits' => [
                'x' => 50,
                'instagram' => 30,
            ],
            'country' => 'SA',
        ]);

        $response->assertStatus(201);
        $json = $response->json('data');

        $this->assertEquals(80, (int) $json['comments_limit']);
        $this->assertEquals(50, $json['platform_limits']['x']);
        $this->assertEquals(30, $json['platform_limits']['instagram']);

        TenantContext::forgetTenant();
    }

    public function test_scrape_keywords_job_accumulates_articles_across_all_selected_platforms_without_overwriting(): void
    {
        $tenant = Tenant::create([
            'name' => 'Multi Platform Tenant',
            'slug' => 'multi-plat-tenant',
            'status' => Tenant::STATUS_ACTIVE,
            'is_active' => true,
        ]);

        $col = Collection::create([
            'tenant_id' => $tenant->id,
            'name' => 'Campaign Multi',
            'keywords' => 'تبوك, صحة',
            'platform' => 'x,facebook,instagram,tiktok,web',
            'platform_limits' => [
                'x' => 5,
                'facebook' => 5,
                'instagram' => 5,
                'tiktok' => 5,
                'web' => 5,
            ],
            'status' => Collection::STATUS_PENDING,
        ]);

        TenantContext::setTenant($tenant);

        $fakePosts = [
            'x_posts' => [
                ['post_id' => 'x_1', 'text' => 'خبر من تبوك على تويتر', 'author' => 'user_x', 'url' => 'https://x.com/1'],
                ['post_id' => 'x_2', 'text' => 'صحة تبوك تقرير رقم 2', 'author' => 'user_x2', 'url' => 'https://x.com/2'],
            ],
            'facebook_posts' => [
                ['post_id' => 'fb_1', 'text' => 'منشور فيسبوك عن صحة تبوك', 'author' => 'user_fb', 'url' => 'https://facebook.com/1'],
            ],
            'instagram_posts' => [
                ['post_id' => 'ig_1', 'text' => 'صورة إنستغرام لمركز صحة تبوك', 'author' => 'user_ig', 'url' => 'https://instagram.com/1'],
            ],
            'tiktok_posts' => [
                ['post_id' => 'tt_1', 'text' => 'فيديو تيك توك مجمع تبوك الصحي', 'author' => 'user_tt', 'url' => 'https://tiktok.com/1'],
            ],
            'web_posts' => [
                ['post_id' => 'wb_1', 'text' => 'مقال صحفي إخباري عن صحة تبوك', 'author' => 'جريدة الوطن', 'url' => 'https://alwatan.com/1'],
            ],
        ];

        $this->mock(AiScraperService::class, function (MockInterface $mock) use ($fakePosts) {
            $mock->shouldReceive('scrapeByKeywords')
                ->andReturn([
                    'status' => 'success',
                    'posts_by_platform' => $fakePosts,
                ]);
        });

        $job = new \App\Jobs\ScrapeKeywordsJob(
            tenantId: $tenant->id,
            keywords: ['تبوك', 'صحة'],
            platforms: ['x', 'facebook', 'instagram', 'tiktok', 'web'],
            country: 'SA',
            collectionId: $col->id,
            dateFrom: null,
            dateTo: null,
            limit: 25,
            platformLimits: $col->platform_limits
        );

        $job->handle(
            app(AiScraperService::class),
            app(\App\Services\ApifyScraperService::class),
            app(\App\Services\GeminiAnalyticsService::class)
        );

        TenantContext::setTenant($tenant);
        $col->refresh();

        $this->assertEquals(Collection::STATUS_COMPLETED, $col->status);
        $this->assertEquals(6, $col->articles()->count());

        $byPlat = $col->articles()->get()->groupBy(fn($a) => $a->raw_data['platform'] ?? 'none');
        $this->assertCount(2, $byPlat['x']);
        $this->assertCount(1, $byPlat['facebook']);
        $this->assertCount(1, $byPlat['instagram']);
        $this->assertCount(1, $byPlat['tiktok']);
        $this->assertCount(1, $byPlat['web']);

        TenantContext::forgetTenant();
    }
}
