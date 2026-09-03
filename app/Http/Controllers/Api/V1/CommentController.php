<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Jobs\ScrapePostCommentsJob;
use App\Models\Comment;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    use ApiResponse;

    /**
     * List comments with filtering and pagination.
     */
    public function index(Request $request)
    {
        $query = Comment::query();

        if ($request->has('article_id')) {
            $query->where('article_id', $request->article_id);
        }

        if ($request->has('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->has('sentiment')) {
            $query->where('sentiment', $request->sentiment);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('comment_text', 'like', "%{$search}%");
        }

        $comments = $query->latest()->paginate($request->get('per_page', 20));

        return $this->paginated($comments, CommentResource::class);
    }

    /**
     * Trigger comment scraping for post URLs via AI microservice.
     */
    public function scrape(Request $request)
    {
        $request->validate([
            'insta_urls' => ['sometimes', 'array'],
            'facebook_urls' => ['sometimes', 'array'],
            'tiktok_urls' => ['sometimes', 'array'],
            'twitter_urls' => ['sometimes', 'array'],
            'article_id' => ['sometimes', 'nullable', 'exists:articles,id'],
        ]);

        $tenantId = TenantContext::getTenantId();
        if (!$tenantId) {
            return $this->error(__('messages.forbidden'), 403);
        }

        $urlsByPlatform = [
            'insta_urls' => $request->get('insta_urls', []),
            'facebook_urls' => $request->get('facebook_urls', []),
            'tiktok_urls' => $request->get('tiktok_urls', []),
            'twitter_urls' => $request->get('twitter_urls', []),
        ];

        ScrapePostCommentsJob::dispatch(
            $tenantId,
            $urlsByPlatform,
            $request->get('article_id')
        );

        return $this->success(null, 'Scraping task queued successfully. Results will be processed asynchronously.', 202);
    }
}
