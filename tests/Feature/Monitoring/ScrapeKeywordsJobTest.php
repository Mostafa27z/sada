<?php

namespace Tests\Feature\Monitoring;

use App\Jobs\ScrapeKeywordsJob;
use App\Models\Article;
use App\Models\Tenant;
use App\Services\AiScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ScrapeKeywordsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_scrape_keywords_job_saves_structured_posts_and_prevents_duplicates(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'is_active' => true,
        ]);

        $mockPayload = [
            'status' => 'success',
            'posts_by_platform' => [
                'x_posts' => [
                    [
                        'post_id' => '123456789',
                        'external_id' => 'x_123456789',
                        'author' => 'TwitterUser',
                        'text' => 'Testing X post content for social listening',
                        'url' => 'https://x.com/TwitterUser/status/123456789',
                        'created_at' => '2026-09-03T12:00:00Z',
                        'platform' => 'x',
                        'country' => 'Saudi Arabia',
                    ]
                ],
            ],
            'analytics' => [
                'overall_sentiment_summary' => ['overall_sentiment' => 'positive']
            ]
        ];

        $this->mock(AiScraperService::class, function (MockInterface $mock) use ($mockPayload) {
            $mock->shouldReceive('scrapeByKeywords')
                ->twice()
                ->with(['Saudi Arabia'], ['x'], 'Saudi Arabia')
                ->andReturn($mockPayload);
        });

        // First execution
        $job = new ScrapeKeywordsJob($tenant->id, ['Saudi Arabia'], ['x'], 'Saudi Arabia');
        $job->handle(app(AiScraperService::class));

        $this->assertDatabaseHas('articles', [
            'tenant_id' => $tenant->id,
            'external_id' => 'x_123456789',
            'url' => 'https://x.com/TwitterUser/status/123456789',
            'author' => 'TwitterUser',
            'country' => 'Saudi Arabia',
        ]);
        $this->assertEquals(1, Article::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        // Second execution (duplicate payload test)
        $job->handle(app(AiScraperService::class));

        // Total count should still be 1 due to firstOrCreate deduplication
        $this->assertEquals(1, Article::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }
}
