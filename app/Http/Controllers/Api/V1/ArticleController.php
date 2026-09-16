<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    use ApiResponse;

    /**
     * List tenant articles with advanced filtering and pagination.
     */
    public function index(Request $request)
    {
        $query = Article::query()->with(['source', 'keywords']);

        // Search in title or content
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        // Filter by sentiment
        if ($request->has('sentiment')) {
            $query->where('sentiment', $request->sentiment);
        }

        // Filter by language
        if ($request->has('language')) {
            $query->where('language', $request->language);
        }

        // Filter by platform
        if ($request->has('platform') && !empty($request->platform) && $request->platform !== 'all') {
            $plat = strtolower(trim((string) $request->platform));
            $query->where(function ($q) use ($plat) {
                $q->where('raw_data->platform', $plat)
                  ->orWhereHas('source', fn($sq) => $sq->where('platform', $plat)->orWhere('type', $plat));
            });
        }

        // Filter by country
        if ($request->has('country')) {
            $query->where('country', $request->country);
        }

        // Filter by source
        if ($request->has('source_id')) {
            $query->where('source_id', $request->source_id);
        }

        // Filter by keyword
        if ($request->has('keyword_id')) {
            $query->whereHas('keywords', function ($q) use ($request) {
                $q->where('keywords.id', $request->keyword_id);
            });
        }

        // Date range filters
        if ($request->has('date_from')) {
            $dateFrom = strlen($request->date_from) === 10 ? $request->date_from . ' 00:00:00' : $request->date_from;
            $query->where('published_at', '>=', $dateFrom);
        }

        if ($request->has('date_to')) {
            $dateTo = strlen($request->date_to) === 10 ? $request->date_to . ' 23:59:59' : $request->date_to;
            $query->where('published_at', '<=', $dateTo);
        }

        // Sorting
        $sortBy = in_array($request->get('sort_by'), ['published_at', 'created_at', 'sentiment_score'])
            ? $request->get('sort_by')
            : 'published_at';

        $sortDir = strtolower($request->get('sort_direction')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        $articles = $query->paginate($request->get('per_page', 20));

        return $this->paginated($articles, ArticleResource::class);
    }

    /**
     * Get specific article details.
     */
    public function show(int $id)
    {
        $article = Article::with(['source', 'keywords'])->find($id);

        if (!$article) {
            return $this->error(__('messages.not_found'), 404);
        }

        return $this->success(new ArticleResource($article));
    }
}
