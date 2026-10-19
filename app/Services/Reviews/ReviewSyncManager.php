<?php

namespace App\Services\Reviews;

use App\DTOs\NormalizedReview;
use App\Models\LocationReview;
use App\Models\TenantLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReviewSyncManager
{
    public function __construct(
        protected GoogleBusinessProfileService $gbpService,
        protected ApifyLocationReviewFetcher $apifyFetcher
    ) {}

    /**
     * Sync reviews for a given location, executing GBP first with Apify fallback.
     *
     * @param TenantLocation $location
     * @param int $maxReviews
     * @return array
     */
    public function sync(TenantLocation $location, int $maxReviews = 50): array
    {
        $location->update([
            'sync_status' => 'syncing',
            'last_error_message' => null,
        ]);

        $reviews = [];
        $usedProvider = 'apify';

        // 1. Try Google Business Profile if configured and verified
        if ($location->provider === 'gbp' && $location->gbp_location_id && $location->gbp_refresh_token) {
            try {
                $reviews = $this->gbpService->fetchReviews($location, $maxReviews);
                $usedProvider = 'gbp';
            } catch (\Throwable $e) {
                Log::warning("GBP API sync failed for location {$location->id}, falling back to Apify. Error: " . $e->getMessage());
                // Fallback will execute below
            }
        }

        // 2. Fallback to Apify if GBP didn't succeed or wasn't configured
        if (empty($reviews)) {
            try {
                $reviews = $this->apifyFetcher->fetchReviews($location, $maxReviews);
                $usedProvider = 'apify';
            } catch (\Throwable $e) {
                Log::error("Apify scraper failed for location {$location->id}: " . $e->getMessage());
                $location->update([
                    'sync_status' => 'failed',
                    'last_error_message' => $e->getMessage(),
                ]);

                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'synced_count' => 0,
                ];
            }
        }

        // 3. Upsert normalized reviews and update location aggregates
        $syncedCount = $this->upsertReviews($location, $reviews);

        return [
            'success' => true,
            'provider' => $usedProvider,
            'synced_count' => $syncedCount,
        ];
    }

    /**
     * Persist normalized reviews into the database and recalculate location aggregates.
     *
     * @param TenantLocation $location
     * @param NormalizedReview[] $reviews
     * @return int
     */
    public function upsertReviews(TenantLocation $location, array $reviews): int
    {
        $count = 0;

        DB::transaction(function () use ($location, $reviews, &$count) {
            foreach ($reviews as $rev) {
                LocationReview::updateOrCreate(
                    [
                        'tenant_location_id' => $location->id,
                        'google_review_id'   => $rev->googleReviewId,
                    ],
                    [
                        'tenant_id'                 => $location->tenant_id,
                        'source'                    => $rev->source,
                        'author_name'               => $rev->authorName,
                        'author_photo_url'          => $rev->authorPhotoUrl,
                        'rating'                    => $rev->rating,
                        'text'                      => $rev->text,
                        'published_at'              => $rev->publishedAt,
                        'relative_time_description' => $rev->relativeTimeDescription,
                        'response_text'             => $rev->responseText,
                        'response_published_at'     => $rev->responsePublishedAt,
                        'is_responded'              => !empty($rev->responseText),
                        'likes_count'               => $rev->likesCount,
                    ]
                );
                $count++;
            }

            // Recalculate Aggregates
            $stats = LocationReview::where('tenant_location_id', $location->id)
                ->selectRaw('COUNT(*) as total, AVG(rating) as avg_rating, SUM(CASE WHEN is_responded = 0 THEN 1 ELSE 0 END) as unanswered')
                ->first();

            $distribution = LocationReview::where('tenant_location_id', $location->id)
                ->selectRaw('rating, COUNT(*) as count')
                ->groupBy('rating')
                ->pluck('count', 'rating')
                ->toArray();

            $distArray = [
                '1' => (int) ($distribution[1] ?? 0),
                '2' => (int) ($distribution[2] ?? 0),
                '3' => (int) ($distribution[3] ?? 0),
                '4' => (int) ($distribution[4] ?? 0),
                '5' => (int) ($distribution[5] ?? 0),
            ];

            $location->update([
                'total_reviews'       => (int) ($stats->total ?? 0),
                'average_rating'      => round((float) ($stats->avg_rating ?? 0.0), 1),
                'unanswered_reviews'  => (int) ($stats->unanswered ?? 0),
                'rating_distribution' => $distArray,
                'sync_status'         => 'completed',
                'last_synced_at'      => now(),
                'last_error_message'  => null,
            ]);
        });

        return $count;
    }
}
