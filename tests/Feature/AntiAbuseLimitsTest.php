<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GeminiAnalyticsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AntiAbuseLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_industry_news_trigger_is_strictly_limited_to_three_times_daily(): void
    {
        Queue::fake();

        $plan = Plan::create([
            'name' => 'Enterprise Plan',
            'slug' => 'enterprise',
            'price' => 999,
            'max_keywords' => 100,
            'max_sources' => 100,
            'max_users' => 10,
            'max_campaigns' => 50,
            'has_news' => true,
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'name' => 'Medical Center',
            'domain' => 'med-center',
            'plan_id' => $plan->id,
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'current_tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
        $user->tenants()->attach($tenant->id);

        Sanctum::actingAs($user);
        TenantContext::setTenant($tenant);

        // 1st Trigger: Success, triggers_left = 2
        $res1 = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/api/v1/industry-news/trigger');
        $res1->assertStatus(200);
        $res1->assertJsonPath('data.triggers_left', 2);
        $res1->assertJsonPath('data.triggers_used', 1);

        // 2nd Trigger: Success, triggers_left = 1
        $res2 = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/api/v1/industry-news/trigger');
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.triggers_left', 1);
        $res2->assertJsonPath('data.triggers_used', 2);

        // 3rd Trigger: Success, triggers_left = 0
        $res3 = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/api/v1/industry-news/trigger');
        $res3->assertStatus(200);
        $res3->assertJsonPath('data.triggers_left', 0);
        $res3->assertJsonPath('data.triggers_used', 3);

        // 4th Trigger: BLOCKED with 429 Too Many Requests
        $res4 = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/api/v1/industry-news/trigger');
        $res4->assertStatus(429);
        $res4->assertJsonPath('errors.code', 'trigger_limit_exceeded');
        $res4->assertJsonPath('errors.triggers_left', 0);

        // Verify today's endpoint returns triggers_left = 0
        $todayRes = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->getJson('/api/v1/industry-news/today');
        $todayRes->assertStatus(200);
        $todayRes->assertJsonPath('data.triggers_left', 0);
        $todayRes->assertJsonPath('data.triggers_used', 3);
        $todayRes->assertJsonPath('data.max_triggers', 3);
    }

    public function test_master_post_generation_is_strictly_limited_to_three_times_daily(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'current_tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
        $user->tenants()->attach($tenant->id);

        Sanctum::actingAs($user);
        TenantContext::setTenant($tenant);

        // Mock GeminiAnalyticsService
        $mockGemini = Mockery::mock(GeminiAnalyticsService::class);
        $mockGemini->shouldReceive('generateMasterTrendPost')
            ->times(3)
            ->andReturn([
                'master_post' => 'Generated AI Master Post',
                'trend_summary' => 'Summary of trends',
                'key_points' => ['Point 1', 'Point 2'],
                'engagement_question' => 'What are your thoughts?',
            ]);

        $this->app->instance(GeminiAnalyticsService::class, $mockGemini);

        $payload = [
            'topic' => 'الذكاء الاصطناعي في الصحة',
            'posts' => [
                ['text' => 'تجربة ممتازة في المستشفى', 'platform' => 'twitter', 'sentiment' => 'positive'],
            ],
            'tone' => 'engaging',
        ];

        // 1st Generation: Success, generations_left = 2
        $res1 = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/api/v1/trends/master-post', $payload);
        $res1->assertStatus(200);
        $res1->assertJsonPath('data.generations_left', 2);
        $res1->assertJsonPath('data.generations_used', 1);

        // Status check should report 2 left
        $statusRes1 = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->getJson('/api/v1/trends/master-post-status?topic=' . urlencode($payload['topic']));
        $statusRes1->assertStatus(200);
        $statusRes1->assertJsonPath('data.generations_left', 2);
        $statusRes1->assertJsonPath('data.generations_used', 1);

        // 2nd Generation: Success, generations_left = 1
        $res2 = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/api/v1/trends/master-post', $payload);
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.generations_left', 1);
        $res2->assertJsonPath('data.generations_used', 2);

        // 3rd Generation: Success, generations_left = 0
        $res3 = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/api/v1/trends/master-post', $payload);
        $res3->assertStatus(200);
        $res3->assertJsonPath('data.generations_left', 0);
        $res3->assertJsonPath('data.generations_used', 3);

        // 4th Generation: BLOCKED with 429 Too Many Requests
        $res4 = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/api/v1/trends/master-post', $payload);
        $res4->assertStatus(429);
        $res4->assertJsonPath('errors.code', 'generation_limit_exceeded');
        $res4->assertJsonPath('errors.generations_left', 0);

        // Final status check: 0 left
        $statusResFinal = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->getJson('/api/v1/trends/master-post-status?topic=' . urlencode($payload['topic']));
        $statusResFinal->assertStatus(200);
        $statusResFinal->assertJsonPath('data.generations_left', 0);
        $statusResFinal->assertJsonPath('data.generations_used', 3);
    }

    public function test_admin_plan_and_tenant_requests_endpoints_succeed(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $res1 = $this->getJson('/api/v1/admin/plan-requests?status=pending&page=1');
        $res1->assertStatus(200);
        $res1->assertJsonPath('success', true);

        $res2 = $this->getJson('/api/v1/admin/tenant-requests?status=pending&page=1');
        $res2->assertStatus(200);
        $res2->assertJsonPath('success', true);
    }
}
