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
                        'text' => 'Testing X post content for social listening in Saudi Arabia',
                        'url' => 'https://x.com/TwitterUser/status/123456789',
                        'created_at' => now()->subDays(2)->toIso8601String(),
                        'platform' => 'x',
                        'country' => 'SA',
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
                ->with(
                    ['Saudi Arabia'],
                    ['x'],
                    'SA',
                    \Mockery::any(),
                    \Mockery::any(),
                    \Mockery::any(),
                    \Mockery::any()
                )
                ->andReturn($mockPayload);
        });

        // First execution
        $job = new ScrapeKeywordsJob($tenant->id, ['Saudi Arabia'], ['x'], 'SA');
        $job->handle(app(AiScraperService::class));

        $this->assertDatabaseHas('articles', [
            'tenant_id' => $tenant->id,
            'external_id' => 'x_123456789',
            'url' => 'https://x.com/TwitterUser/status/123456789',
            'author' => 'TwitterUser',
            'country' => 'SA',
        ]);
        $this->assertEquals(1, Article::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        // Second execution (duplicate payload test)
        $job->handle(app(AiScraperService::class));

        // Total count should still be 1 due to firstOrCreate deduplication
        $this->assertEquals(1, Article::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_scrape_keywords_job_preserves_authentic_platform_metrics(): void
    {
        $tenant = Tenant::create([
            'name' => 'Metric Tenant',
            'slug' => 'metric-tenant',
            'is_active' => true,
        ]);

        $mockPayload = [
            'status' => 'success',
            'posts_by_platform' => [
                'x_posts' => [
                    [
                        'post_id' => '987654321',
                        'external_id' => 'x_987654321',
                        'author' => 'AuthenticUser',
                        'text' => 'Post with genuine interactions in Saudi Arabia',
                        'url' => 'https://x.com/AuthenticUser/status/987654321',
                        'created_at' => now()->toIso8601String(),
                        'platform' => 'x',
                        'country' => 'SA',
                        'views' => 30000,
                        'likes' => 26,
                        'retweets' => 6,
                        'replies' => 7,
                        'reach' => '30K',
                        'engagement' => '26',
                    ]
                ],
            ],
            'analytics' => []
        ];

        $this->mock(AiScraperService::class, function (MockInterface $mock) use ($mockPayload) {
            $mock->shouldReceive('scrapeByKeywords')
                ->once()
                ->andReturn($mockPayload);
        });

        $job = new ScrapeKeywordsJob($tenant->id, ['Saudi Arabia'], ['x'], 'SA');
        $job->handle(app(AiScraperService::class));

        $article = Article::withoutGlobalScopes()->where('external_id', 'x_987654321')->first();
        $this->assertNotNull($article);
        $this->assertEquals('30K', $article->raw_data['reach']);
        $this->assertEquals('26', $article->raw_data['engagement']);
        $this->assertEquals(30000, $article->raw_data['views']);
        $this->assertEquals(26, $article->raw_data['likes']);
        $this->assertEquals(6, $article->raw_data['retweets']);
        $this->assertEquals(7, $article->raw_data['replies']);

        $resource = (new \App\Http\Resources\ArticleResource($article))->resolve();
        $this->assertEquals('30K', $resource['reach']);
        $this->assertEquals('26', $resource['engagement']);
        $this->assertEquals(30000, $resource['views']);
        $this->assertEquals(26, $resource['likes']);
    }
}
