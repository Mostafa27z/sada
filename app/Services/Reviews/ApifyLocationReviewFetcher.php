<?php

namespace App\Services\Reviews;

use App\DTOs\NormalizedReview;
use App\Models\TenantLocation;
use App\Services\ApifyScraperService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ApifyLocationReviewFetcher implements ReviewFetcherInterface
{
    public function __construct(
        protected ApifyScraperService $apifyService
    ) {}

    /**
     * Fetch and normalize reviews using Apify Google Maps actors.
     *
     * @param TenantLocation $location
     * @param int $maxReviews
     * @return NormalizedReview[]
     */
    public function fetchReviews(TenantLocation $location, int $maxReviews = 50): array
    {
        $rawItems = $this->apifyService->fetchGoogleMapsReviews($location->maps_url, $maxReviews);
        $normalized = [];

        foreach ($rawItems as $item) {
            // Update location metadata if available from Google
            $metaUpdates = [];
            if (!empty($item['placeId']) && empty($location->google_place_id)) {
                $metaUpdates['google_place_id'] = $item['placeId'];
            }
            if (!empty($item['cid']) && empty($location->google_cid)) {
                $metaUpdates['google_cid'] = (string) $item['cid'];
            }
            if (!empty($item['address']) && empty($location->address)) {
                $metaUpdates['address'] = $item['address'];
            }
            if (!empty($metaUpdates)) {
                $location->update($metaUpdates);
            }

            // Check if item itself is a place containing a 'reviews' array or if the dataset is an array of reviews
            $reviewsList = [];
            if (isset($item['reviews']) && is_array($item['reviews'])) {
                $reviewsList = $item['reviews'];
            } elseif (isset($item['text']) || isset($item['stars']) || isset($item['reviewId'])) {
                $reviewsList = [$item];
            }

            foreach ($reviewsList as $rev) {
                $reviewText = $rev['text'] ?? $rev['reviewText'] ?? null;
                $authorName = $rev['name'] ?? $rev['reviewerName'] ?? $rev['authorTitle'] ?? 'Google User';
                $rating = (int) ($rev['stars'] ?? $rev['rating'] ?? 5);

                // Publish date resolution
                $publishedAt = null;
                $rawDate = $rev['publishAt'] ?? $rev['publishedAtDate'] ?? $rev['reviewDate'] ?? null;
                if ($rawDate) {
                    try {
                        $publishedAt = Carbon::parse($rawDate)->toDateTimeString();
                    } catch (\Exception) {
                        $publishedAt = now()->toDateTimeString();
                    }
                } else {
                    $publishedAt = now()->toDateTimeString();
                }

                // Unique review identifier
                $reviewId = $rev['reviewId'] ?? $rev['id'] ?? null;
                if (!$reviewId) {
                    // Stable fallback hash based on location + author + publish time or text
                    $reviewId = 'gen_' . md5($location->id . '_' . $authorName . '_' . ($publishedAt ?: '') . '_' . substr($reviewText ?? '', 0, 40));
                }

                // Owner response resolution
                $responseText = $rev['responseFromOwnerText'] ?? $rev['ownerReply'] ?? $rev['response']['text'] ?? null;
                $responsePublishedAt = null;
                $rawResponseDate = $rev['responseFromOwnerDate'] ?? $rev['response']['publishedAt'] ?? null;
                if ($rawResponseDate) {
                    try {
                        $responsePublishedAt = Carbon::parse($rawResponseDate)->toDateTimeString();
                    } catch (\Exception) {
                        $responsePublishedAt = null;
                    }
                }

                $normalized[] = new NormalizedReview(
                    googleReviewId: (string) $reviewId,
                    authorName: (string) $authorName,
                    authorPhotoUrl: $rev['reviewerPhotoUrl'] ?? $rev['authorPhoto'] ?? null,
                    rating: max(1, min(5, $rating)),
                    text: $reviewText,
                    publishedAt: $publishedAt,
                    relativeTimeDescription: $rev['publishedAtDate'] ?? $rev['reviewDate'] ?? null,
                    responseText: $responseText,
                    responsePublishedAt: $responsePublishedAt,
                    likesCount: (int) ($rev['likesCount'] ?? 0),
                    source: 'apify'
                );
            }
        }

        return $normalized;
    }
}
