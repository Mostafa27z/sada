<?php

namespace App\Services\Reviews;

use App\DTOs\NormalizedReview;
use App\Models\TenantLocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleBusinessProfileService implements ReviewFetcherInterface
{
    /**
     * Fetch reviews from Google Business Profile API.
     *
     * @param TenantLocation $location
     * @param int $maxReviews
     * @return NormalizedReview[]
     * @throws \Exception
     */
    public function fetchReviews(TenantLocation $location, int $maxReviews = 50): array
    {
        $accessToken = $this->getValidAccessToken($location);
        $accountId = $location->gbp_account_id;
        $locationId = $location->gbp_location_id;

        if (!$accountId || !$locationId) {
            throw new \Exception("Missing GBP account_id or location_id for location ID {$location->id}");
        }

        // Google My Business v4 Reviews API endpoint
        $url = "https://mybusiness.googleapis.com/v4/accounts/{$accountId}/locations/{$locationId}/reviews";

        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->get($url, [
                'pageSize' => min($maxReviews, 50),
            ]);

        if (!$response->successful()) {
            throw new \Exception("GBP API returned HTTP {$response->status()}: " . $response->body());
        }

        $data = $response->json();
        $reviews = $data['reviews'] ?? [];
        $normalized = [];

        foreach ($reviews as $rev) {
            $reviewer = $rev['reviewer'] ?? [];
            $starRating = match ($rev['starRating'] ?? '') {
                'ONE' => 1,
                'TWO' => 2,
                'THREE' => 3,
                'FOUR' => 4,
                'FIVE' => 5,
                default => 5,
            };

            $publishedAt = isset($rev['createTime']) ? Carbon::parse($rev['createTime'])->toDateTimeString() : now()->toDateTimeString();
            
            $reviewReply = $rev['reviewReply'] ?? null;
            $responseText = $reviewReply['comment'] ?? null;
            $responsePublishedAt = isset($reviewReply['updateTime']) ? Carbon::parse($reviewReply['updateTime'])->toDateTimeString() : null;

            $normalized[] = new NormalizedReview(
                googleReviewId: (string) ($rev['reviewId'] ?? $rev['name']),
                authorName: (string) ($reviewer['displayName'] ?? 'Google User'),
                authorPhotoUrl: $reviewer['profilePhotoUrl'] ?? null,
                rating: $starRating,
                text: $rev['comment'] ?? null,
                publishedAt: $publishedAt,
                relativeTimeDescription: null,
                responseText: $responseText,
                responsePublishedAt: $responsePublishedAt,
                likesCount: 0,
                source: 'gbp'
            );
        }

        return $normalized;
    }

    /**
     * Post a reply to a Google review via GBP API.
     *
     * @param TenantLocation $location
     * @param string $googleReviewId
     * @param string $replyText
     * @return array
     * @throws \Exception
     */
    public function replyToReview(TenantLocation $location, string $googleReviewId, string $replyText): array
    {
        $accessToken = $this->getValidAccessToken($location);
        $accountId = $location->gbp_account_id;
        $locationId = $location->gbp_location_id;

        $url = "https://mybusiness.googleapis.com/v4/accounts/{$accountId}/locations/{$locationId}/reviews/{$googleReviewId}/reply";

        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->put($url, [
                'comment' => $replyText,
            ]);

        if (!$response->successful()) {
            throw new \Exception("GBP Reply API failed: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Ensure we have a valid, unexpired OAuth access token, refreshing if needed.
     */
    public function getValidAccessToken(TenantLocation $location): string
    {
        if ($location->gbp_access_token && $location->gbp_token_expires_at && $location->gbp_token_expires_at->isFuture()) {
            return $location->gbp_access_token;
        }

        if (!$location->gbp_refresh_token) {
            throw new \Exception("No GBP refresh token available for location ID {$location->id}");
        }

        // Refresh token from Google OAuth endpoint
        $clientId = config('services.google.client_id') ?: env('GOOGLE_CLIENT_ID');
        $clientSecret = config('services.google.client_secret') ?: env('GOOGLE_CLIENT_SECRET');

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $location->gbp_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            throw new \Exception("Failed to refresh GBP OAuth token: " . $response->body());
        }

        $data = $response->json();
        $newAccessToken = $data['access_token'];
        $expiresIn = $data['expires_in'] ?? 3600;

        $location->update([
            'gbp_access_token' => $newAccessToken,
            'gbp_token_expires_at' => now()->addSeconds($expiresIn - 60),
        ]);

        return $newAccessToken;
    }
}
