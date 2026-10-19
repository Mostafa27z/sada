<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationReviewResource;
use App\Models\LocationReview;
use App\Models\TenantLocation;
use App\Services\Reviews\GoogleBusinessProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationReviewController extends Controller
{
    use ApiResponse;

    /**
     * Get paginated reviews for a specific location.
     */
    public function index(Request $request, int $locationId): JsonResponse
    {
        $location = TenantLocation::findOrFail($locationId);

        $query = LocationReview::where('tenant_location_id', $location->id);

        // Filter by Star Rating
        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->input('rating'));
        }

        // Filter by Sentiment
        if ($request->filled('sentiment') && in_array($request->input('sentiment'), ['positive', 'neutral', 'negative'])) {
            $sentiment = $request->input('sentiment');
            $query->where(function ($q) use ($sentiment) {
                $q->where('sentiment', $sentiment);
                if ($sentiment === 'positive') {
                    $q->orWhere(function ($sub) {
                        $sub->whereNull('sentiment')->where('rating', '>=', 4);
                    });
                } elseif ($sentiment === 'neutral') {
                    $q->orWhere(function ($sub) {
                        $sub->whereNull('sentiment')->where('rating', 3);
                    });
                } elseif ($sentiment === 'negative') {
                    $q->orWhere(function ($sub) {
                        $sub->whereNull('sentiment')->where('rating', '<=', 2);
                    });
                }
            });
        }

        // Filter by Owner Response Status
        if ($request->input('status') === 'responded') {
            $query->where('is_responded', true);
        } elseif ($request->input('status') === 'unresponded') {
            $query->where('is_responded', false);
        }

        // Search in reviewer name or review text
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('author_name', 'like', "%{$search}%")
                  ->orWhere('text', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'newest');
        switch ($sortBy) {
            case 'highest_rating':
                $query->orderByDesc('rating')->orderByDesc('published_at');
                break;
            case 'lowest_rating':
                $query->orderBy('rating')->orderByDesc('published_at');
                break;
            case 'newest':
            default:
                $query->orderByDesc('published_at');
                break;
        }

        $perPage = (int) $request->input('per_page', 15);
        $reviews = $query->paginate(max(1, min(100, $perPage)));

        return $this->paginated($reviews, LocationReviewResource::class);
    }

    /**
     * Post a reply to a review (GBP OAuth connection required).
     */
    public function reply(
        Request $request,
        int $locationId,
        int $reviewId,
        GoogleBusinessProfileService $gbpService
    ): JsonResponse {
        $location = TenantLocation::findOrFail($locationId);
        $review = LocationReview::where('tenant_location_id', $location->id)->findOrFail($reviewId);

        $validated = $request->validate([
            'reply_text' => ['required', 'string', 'max:4000'],
        ]);

        // Capability check
        if (!$location->can_reply || $location->provider !== 'gbp' || !$location->gbp_location_id) {
            return $this->error(
                'Posting replies requires connecting this location with an authorized Google Business Profile account.',
                403,
                ['error_code' => 'REPLY_UNAVAILABLE']
            );
        }

        try {
            $gbpService->replyToReview($location, $review->google_review_id, $validated['reply_text']);

            $review->update([
                'response_text' => $validated['reply_text'],
                'response_published_at' => now(),
                'is_responded' => true,
            ]);

            // Decrement unanswered counter
            $location->decrement('unanswered_reviews');

            return $this->success(
                new LocationReviewResource($review),
                'Reply published to Google Maps successfully.'
            );
        } catch (\Throwable $e) {
            return $this->error('Failed to post reply to Google: ' . $e->getMessage(), 500);
        }
    }
}
