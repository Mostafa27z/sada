<?php

namespace Tests\Feature;

use App\DTOs\NormalizedReview;
use App\Models\LocationReview;
use App\Models\Tenant;
use App\Models\TenantLocation;
use App\Models\User;
use App\Services\Reviews\ReviewSyncManager;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantLocationReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'current_tenant_id' => $this->tenant->id,
        ]);

        $this->tenant->users()->attach($this->user->id, ['role_id' => 1, 'is_owner' => true]);
        TenantContext::setTenant($this->tenant);
    }

    public function test_can_upsert_reviews_and_calculate_aggregates(): void
    {
        $location = TenantLocation::create([
            'tenant_id' => $this->tenant->id,
            'business_name' => 'Coffee House',
            'maps_url' => 'https://maps.app.goo.gl/sample',
            'provider' => 'apify',
        ]);

        $syncManager = app(ReviewSyncManager::class);

        $mockReviews = [
            new NormalizedReview(
                googleReviewId: 'rev_1',
                authorName: 'Alice',
                authorPhotoUrl: null,
                rating: 5,
                text: 'Awesome coffee!',
                publishedAt: now()->subDays(2)->toDateTimeString(),
                responseText: 'Thanks Alice!',
                responsePublishedAt: now()->subDays(1)->toDateTimeString(),
                likesCount: 2,
                source: 'apify'
            ),
            new NormalizedReview(
                googleReviewId: 'rev_2',
                authorName: 'Bob',
                authorPhotoUrl: null,
                rating: 3,
                text: 'Decent place, but slow wifi.',
                publishedAt: now()->subDays(1)->toDateTimeString(),
                responseText: null,
                responsePublishedAt: null,
                likesCount: 0,
                source: 'apify'
            ),
        ];

        $count = $syncManager->upsertReviews($location, $mockReviews);
        $this->assertEquals(2, $count);

        $location->refresh();
        $this->assertEquals(2, $location->total_reviews);
        $this->assertEquals(4.0, $location->average_rating);
        $this->assertEquals(1, $location->unanswered_reviews);
        $this->assertEquals(1, $location->rating_distribution[5]);
        $this->assertEquals(1, $location->rating_distribution[3]);

        // Deduplication test: Running upsert with the same IDs should not create duplicates
        $count2 = $syncManager->upsertReviews($location, $mockReviews);
        $this->assertEquals(2, $count2);
        $this->assertEquals(2, LocationReview::where('tenant_location_id', $location->id)->count());
    }

    public function test_api_can_list_locations_and_query_reviews(): void
    {
        $location = TenantLocation::create([
            'tenant_id' => $this->tenant->id,
            'business_name' => 'Downtown Cafe',
            'maps_url' => 'https://maps.app.goo.gl/cafe',
            'provider' => 'apify',
        ]);

        LocationReview::create([
            'tenant_id' => $this->tenant->id,
            'tenant_location_id' => $location->id,
            'google_review_id' => 'rev_api_1',
            'author_name' => 'Charlie',
            'rating' => 5,
            'text' => 'Great espresso',
            'published_at' => now(),
            'source' => 'apify',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/tenant/locations');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        $reviewsResponse = $this->actingAs($this->user)->getJson("/api/v1/tenant/locations/{$location->id}/reviews");
        $reviewsResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.author_name', 'Charlie')
            ->assertJsonPath('data.0.sentiment', 'positive');
    }

    public function test_api_can_reanalyze_location_reviews_with_ai(): void
    {
        $location = TenantLocation::create([
            'tenant_id' => $this->tenant->id,
            'business_name' => 'Burger Joint',
            'maps_url' => 'https://maps.app.goo.gl/burger',
            'provider' => 'apify',
        ]);

        $review1 = LocationReview::create([
            'tenant_id' => $this->tenant->id,
            'tenant_location_id' => $location->id,
            'google_review_id' => 'rev_bj_1',
            'author_name' => 'Zaid',
            'rating' => 5,
            'text' => 'Best burgers and quick service!',
            'published_at' => now(),
            'source' => 'apify',
        ]);

        $review2 = LocationReview::create([
            'tenant_id' => $this->tenant->id,
            'tenant_location_id' => $location->id,
            'google_review_id' => 'rev_bj_2',
            'author_name' => 'Sara',
            'rating' => 2,
            'text' => 'Cold fries and long wait time.',
            'published_at' => now(),
            'source' => 'apify',
        ]);

        $mockGemini = \Mockery::mock(\App\Services\GeminiAnalyticsService::class);
        $mockGemini->shouldReceive('analyzeLocationReviews')
            ->once()
            ->andReturn([
                'summary' => 'ملخص تجريبي ذكي للمطعم.',
                'recommendations' => ['توصية 1 لتحسين الخدمة'],
                'reviews' => [
                    [
                        'id' => $review1->id,
                        'sentiment' => 'positive',
                        'sentiment_score' => 0.9,
                        'ai_analysis' => 'إشادة بالجودة والسرعة',
                    ],
                    [
                        'id' => $review2->id,
                        'sentiment' => 'negative',
                        'sentiment_score' => -0.7,
                        'ai_analysis' => 'شكوى من البرودة ووقت الانتظار',
                    ],
                ],
            ]);
        $this->app->instance(\App\Services\GeminiAnalyticsService::class, $mockGemini);

        $response = $this->actingAs($this->user)->postJson("/api/v1/tenant/locations/{$location->id}/reanalyze");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'location' => [
                        'id',
                        'ai_summary',
                        'ai_recommendations',
                        'ai_analyzed_at',
                    ],
                    'summary',
                    'recommendations',
                    'ai_analyzed_at',
                    'updated_reviews_count',
                ],
            ]);

        $location->refresh();
        $this->assertNotNull($location->ai_summary);
        $this->assertNotEmpty($location->ai_recommendations);
        $this->assertNotNull($location->ai_analyzed_at);

        // Verify individual reviews sentiment and analysis were updated
        $review1->refresh();
        $review2->refresh();
        $this->assertEquals('positive', $review1->sentiment);
        $this->assertEquals('negative', $review2->sentiment);
        $this->assertNotNull($review1->ai_analysis);
        $this->assertNotNull($review2->ai_analysis);
    }

    public function test_api_can_filter_reviews_by_sentiment(): void
    {
        $location = TenantLocation::create([
            'tenant_id' => $this->tenant->id,
            'business_name' => 'Pizza Palace',
            'maps_url' => 'https://maps.app.goo.gl/pizza',
            'provider' => 'apify',
        ]);

        LocationReview::create([
            'tenant_id' => $this->tenant->id,
            'tenant_location_id' => $location->id,
            'google_review_id' => 'rev_p_pos',
            'author_name' => 'Mona',
            'rating' => 5,
            'sentiment' => 'positive',
            'text' => 'Delicious pizza',
            'published_at' => now(),
            'source' => 'apify',
        ]);

        LocationReview::create([
            'tenant_id' => $this->tenant->id,
            'tenant_location_id' => $location->id,
            'google_review_id' => 'rev_p_neg',
            'author_name' => 'Fahad',
            'rating' => 1,
            'sentiment' => 'negative',
            'text' => 'Burnt crust',
            'published_at' => now(),
            'source' => 'apify',
        ]);

        // Query positive only
        $resPos = $this->actingAs($this->user)->getJson("/api/v1/tenant/locations/{$location->id}/reviews?sentiment=positive");
        $resPos->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sentiment', 'positive');

        // Query negative only
        $resNeg = $this->actingAs($this->user)->getJson("/api/v1/tenant/locations/{$location->id}/reviews?sentiment=negative");
        $resNeg->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sentiment', 'negative');
    }
}
