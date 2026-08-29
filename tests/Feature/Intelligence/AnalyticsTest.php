<?php

namespace Tests\Feature\Intelligence;

use App\Models\Alert;
use App\Models\Article;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tenant = Tenant::factory()->create();
        $this->tenant->users()->attach($this->user->id, ['is_owner' => true]);
        $this->user->forceFill(['current_tenant_id' => $this->tenant->id])->save();

        Sanctum::actingAs($this->user);
    }

    public function test_dashboard_stats_endpoint(): void
    {
        TenantContext::setTenant($this->tenant);

        Article::create([
            'title' => 'خبر إيجابي مفرح',
            'sentiment' => 'positive',
            'url' => 'https://example.com/1',
            'published_at' => now(),
        ]);

        Article::create([
            'title' => 'خبر سلبي',
            'sentiment' => 'negative',
            'url' => 'https://example.com/2',
            'published_at' => now(),
        ]);

        $response = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson('/api/v1/dashboard/stats');

        $response->assertOk()
            ->assertJsonPath('data.articles.total', 2)
            ->assertJsonPath('data.articles.positive', 1)
            ->assertJsonPath('data.articles.negative', 1);
    }

    public function test_analytics_sentiment_and_volume_endpoints(): void
    {
        TenantContext::setTenant($this->tenant);

        Article::create([
            'title' => 'مقال تحليل المشاعر',
            'sentiment' => 'positive',
            'url' => 'https://example.com/sentiment-1',
            'published_at' => now(),
        ]);

        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson('/api/v1/analytics/sentiment')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.distribution.positive.count', 1);

        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson('/api/v1/analytics/volume')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_alert_rules_crud_and_alerts_resolution(): void
    {
        // 1. Create Alert Rule
        $ruleResponse = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson('/api/v1/alert-rules', [
                'name' => 'تنبيه الأخبار السلبية',
                'trigger_type' => 'sentiment',
                'sentiment' => 'negative',
                'channels' => ['email', 'database'],
            ]);

        $ruleResponse->assertStatus(201)
            ->assertJsonPath('data.name', 'تنبيه الأخبار السلبية');

        // 2. Create Alert for tenant directly
        $alert = TenantContext::withoutTenancy(function () {
            return Alert::create([
                'tenant_id' => $this->tenant->id,
                'title' => 'تنبيه عاجل',
                'message' => 'تم رصد خبر سلبي',
                'type' => 'negative_sentiment',
                'status' => Alert::STATUS_UNREAD,
            ]);
        });

        // 3. Mark Alert as Read
        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson("/api/v1/alerts/{$alert->id}/read")
            ->assertOk()
            ->assertJsonPath('data.status', 'read');

        // 4. Resolve Alert
        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson("/api/v1/alerts/{$alert->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');
    }
}
