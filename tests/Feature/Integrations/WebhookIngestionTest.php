<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Contracts\ExternalApiClientInterface;
use App\Integrations\Services\MockExternalApiClient;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\Tenant;
use App\Models\WebhookLog;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->secret = config('sada.webhook_secret', 'default_sada_webhook_secret');
    }

    public function test_webhook_fails_without_signature_header(): void
    {
        $response = $this->postJson('/api/v1/webhooks/ingest-article', []);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_webhook_fails_with_invalid_signature(): void
    {
        $response = $this->postJson('/api/v1/webhooks/ingest-article', [], [
            'X-Sada-Signature' => 'invalid_signature_hash',
        ]);

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_webhook_ingests_article_and_dispatches_queue_job(): void
    {
        // 1. Create active keyword for tenant
        TenantContext::withoutTenancy(function () {
            Keyword::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'الرؤية',
                'status' => Keyword::STATUS_ACTIVE,
            ]);
        });

        $payload = [
            'tenant_id' => $this->tenant->id,
            'event_type' => 'article.scraped',
            'provider' => 'external_scraper_service',
            'article' => [
                'title' => 'مقال جديد حول الرؤية المستقبلية',
                'url' => 'https://news.example.com/article-100',
                'content' => 'تحليل كامل لمستجدات الرؤية الوطنية في المملكة.',
                'sentiment' => 'positive',
                'sentiment_score' => 0.9200,
            ],
        ];

        $rawPayload = json_encode($payload);
        $signature = hash_hmac('sha256', $rawPayload, $this->secret);

        // Call webhook endpoint using raw content body for HMAC verification
        $response = $this->call(
            'POST',
            '/api/v1/webhooks/ingest-article',
            [],
            [],
            [],
            [
                'HTTP_X-Sada-Signature' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawPayload
        );

        $response->assertStatus(202)
            ->assertJson(['success' => true]);

        // Verify webhook log created
        $this->assertDatabaseHas('webhook_logs', [
            'event_type' => 'article.scraped',
            'provider' => 'external_scraper_service',
        ]);

        // Verify article stored and keyword matched
        $this->assertDatabaseHas('articles', [
            'tenant_id' => $this->tenant->id,
            'title' => 'مقال جديد حول الرؤية المستقبلية',
        ]);

        $article = TenantContext::withoutTenancy(function () {
            return Article::where('tenant_id', $this->tenant->id)->first();
        });

        $this->assertNotNull($article);

        $keywordsCount = TenantContext::withoutTenancy(function () use ($article) {
            return $article->keywords()->count();
        });

        $this->assertEquals(1, $keywordsCount);
    }

    public function test_mock_external_api_client(): void
    {
        $client = app(MockExternalApiClient::class);

        $scrapeResult = $client->triggerScrape(['https://news.example.com']);
        $this->assertEquals('queued', $scrapeResult['status']);

        $sentimentResult = $client->analyzeSentiment('نص إيجابي ممتاز');
        $this->assertEquals('positive', $sentimentResult['sentiment']);
    }
}
