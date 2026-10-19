<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantLocationResource;
use App\Jobs\SyncLocationReviewsJob;
use App\Models\LocationReview;
use App\Models\TenantLocation;
use App\Services\GeminiAnalyticsService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TenantLocationController extends Controller
{
    use ApiResponse;

    /**
     * List all monitored locations for the active tenant.
     */
    public function index(): JsonResponse
    {
        $locations = TenantLocation::latest()->get();
        return $this->success(TenantLocationResource::collection($locations));
    }

    /**
     * Add a new location to monitor.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'maps_url' => ['required', 'string'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $mapsUrl = trim($validated['maps_url']);
        $expandedUrl = $mapsUrl;

        // Auto-resolve shortened links (like maps.app.goo.gl or goo.gl/maps) to retrieve the full URL
        if (str_contains($mapsUrl, 'maps.app.goo.gl') || str_contains($mapsUrl, 'goo.gl/maps') || str_contains($mapsUrl, 'g.co/kgs')) {
            try {
                $response = Http::timeout(6)->withoutRedirecting()->get($mapsUrl);
                $redirectUrl = $response->header('Location');
                if ($redirectUrl) {
                    $expandedUrl = $redirectUrl;
                }
            } catch (\Throwable $e) {
                Log::warning("Could not resolve short Google Maps URL {$mapsUrl}: " . $e->getMessage());
            }
        }

        // Auto-extract coordinates if not explicitly provided
        $latitude = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $longitude = isset($validated['longitude']) ? (float) $validated['longitude'] : null;

        if ($latitude === null || $longitude === null) {
            // Check exact pin coordinates (!3d... !4d...)
            if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $expandedUrl, $coords)) {
                $latitude = (float) $coords[1];
                $longitude = (float) $coords[2];
            } elseif (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $expandedUrl, $coords)) {
                $latitude = (float) $coords[1];
                $longitude = (float) $coords[2];
            } elseif (preg_match('/(?:ll|q)=(-?\d+\.\d+),(-?\d+\.\d+)/', $expandedUrl, $coords)) {
                $latitude = (float) $coords[1];
                $longitude = (float) $coords[2];
            }
        }

        // Auto-extract or fallback business name
        $businessName = $validated['business_name'] ?? null;
        if (!$businessName) {
            // Try extracting from /maps/place/Business+Name/ in the expanded URL
            if (preg_match('/\/maps\/place\/([^\/@?]+)/', $expandedUrl, $matches)) {
                $businessName = urldecode(str_replace('+', ' ', $matches[1]));
            } else {
                $businessName = 'Monitored Google Location';
            }
        }

        $location = TenantLocation::create([
            'business_name' => $businessName,
            'address' => $validated['address'] ?? null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'maps_url' => $mapsUrl,
            'provider' => 'apify',
            'can_reply' => false,
            'is_verified_owner' => false,
            'sync_status' => 'pending',
        ]);

        // Dispatch initial backfill scraping job immediately
        SyncLocationReviewsJob::dispatch($location->id, 50);

        return $this->created(
            new TenantLocationResource($location),
            'Location added successfully. Initial review sync has been queued.'
        );
    }

    /**
     * Show location details and statistics.
     */
    public function show(int $id): JsonResponse
    {
        $location = TenantLocation::findOrFail($id);
        return $this->success(new TenantLocationResource($location));
    }

    /**
     * Trigger manual sync for a location.
     */
    public function sync(int $id): JsonResponse
    {
        $location = TenantLocation::findOrFail($id);

        $location->update([
            'sync_status' => 'syncing',
        ]);

        SyncLocationReviewsJob::dispatch($location->id, 50);

        return $this->success([
            'location_id' => $location->id,
            'sync_status' => 'syncing',
        ], 'Review sync dispatched successfully.');
    }

    /**
     * Re-analyze existing reviews for a location using Gemini AI without re-scraping.
     */
    public function reanalyzeWithAi(int $id, GeminiAnalyticsService $gemini): JsonResponse
    {
        $location = TenantLocation::findOrFail($id);
        $reviews = LocationReview::where('tenant_location_id', $location->id)->get();

        $reviewsData = $reviews->map(function ($rev) {
            return [
                'id' => $rev->id,
                'author_name' => $rev->author_name,
                'rating' => $rev->rating,
                'text' => $rev->text,
            ];
        })->toArray();

        $analysis = $gemini->analyzeLocationReviews($location->business_name, $reviewsData);

        // Update individual reviews in DB
        if (!empty($analysis['reviews'])) {
            $analysisMap = collect($analysis['reviews'])->keyBy('id');
            foreach ($reviews as $rev) {
                if (isset($analysisMap[$rev->id])) {
                    $item = $analysisMap[$rev->id];
                    $rev->update([
                        'sentiment' => $item['sentiment'] ?? $rev->sentiment,
                        'sentiment_score' => isset($item['sentiment_score']) ? (float) $item['sentiment_score'] : $rev->sentiment_score,
                        'ai_analysis' => $item['ai_analysis'] ?? $rev->ai_analysis,
                    ]);
                }
            }
        }

        // Update location summary and recommendations
        $location->update([
            'ai_summary' => $analysis['summary'] ?? null,
            'ai_recommendations' => $analysis['recommendations'] ?? [],
            'ai_analyzed_at' => now(),
        ]);

        return $this->success([
            'location' => new TenantLocationResource($location->fresh()),
            'summary' => $location->ai_summary,
            'recommendations' => $location->ai_recommendations,
            'ai_analyzed_at' => $location->ai_analyzed_at?->toISOString(),
            'updated_reviews_count' => count($analysis['reviews'] ?? []),
        ], 'AI review analysis completed successfully.');
    }

    /**
     * Delete a monitored location and its reviews.
     */
    public function destroy(int $id): JsonResponse
    {
        $location = TenantLocation::findOrFail($id);
        $location->delete();

        return $this->success(null, 'Location removed successfully.');
    }

    /**
     * Generate Google Business Profile OAuth connect URL.
     */
    public function googleConnectUrl(): JsonResponse
    {
        $clientId = config('services.google.client_id') ?: env('GOOGLE_CLIENT_ID');
        $redirectUri = config('services.google.redirect_uri') ?: env('GOOGLE_REDIRECT_URI', url('/api/v1/tenant/locations/google/callback'));

        if (!$clientId) {
            return $this->error('Google Client ID is not configured on the server.', 400);
        }

        $scope = urlencode('https://www.googleapis.com/auth/business.manage');
        $tenantId = TenantContext::getTenantId();

        $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/business.manage',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => (string) $tenantId,
        ]);

        return $this->success(['auth_url' => $authUrl]);
    }

    /**
     * Handle Google OAuth callback to connect GBP account.
     */
    public function googleCallback(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $clientId = config('services.google.client_id') ?: env('GOOGLE_CLIENT_ID');
        $clientSecret = config('services.google.client_secret') ?: env('GOOGLE_CLIENT_SECRET');
        $redirectUri = config('services.google.redirect_uri') ?: env('GOOGLE_REDIRECT_URI', url('/api/v1/tenant/locations/google/callback'));

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $request->input('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (!$tokenResponse->successful()) {
            return $this->error('Failed to exchange Google OAuth code: ' . $tokenResponse->body(), 400);
        }

        $tokenData = $tokenResponse->json();
        $accessToken = $tokenData['access_token'];
        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresIn = $tokenData['expires_in'] ?? 3600;

        // Fetch user's Google Business Profile accounts
        $accountsRes = Http::withToken($accessToken)->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');
        $accounts = $accountsRes->json('accounts', []);

        return $this->success([
            'connected' => true,
            'has_refresh_token' => !empty($refreshToken),
            'accounts_count' => count($accounts),
            'accounts' => $accounts,
        ], 'Google Business Profile connected successfully.');
    }
}
