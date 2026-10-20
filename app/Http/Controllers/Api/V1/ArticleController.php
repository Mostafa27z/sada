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

        // Search in title, content, summary, author, raw_data author, and source name
        if ($request->has('search') && !empty($request->search)) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%")
                  ->orWhere('summary', 'like', "%{$search}%")
                  ->orWhere('author', 'like', "%{$search}%")
                  ->orWhere('raw_data->author', 'like', "%{$search}%")
                  ->orWhere('raw_data->raw_post->author', 'like', "%{$search}%")
                  ->orWhere('raw_data->publisher', 'like', "%{$search}%")
                  ->orWhereHas('source', fn($sq) => $sq->where('name', 'like', "%{$search}%"));
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
                  ->orWhere('raw_data->platform', $plat . '_posts')
                  ->orWhere('raw_data->platform', 'like', "%{$plat}%");

                if ($plat === 'x') {
                    $q->orWhere('raw_data->platform', 'twitter')
                      ->orWhere('url', 'like', '%twitter.com%')
                      ->orWhere('url', 'like', '%x.com%');
                } elseif ($plat === 'facebook') {
                    $q->orWhere('url', 'like', '%facebook.com%')
                      ->orWhere('url', 'like', '%fb.com%')
                      ->orWhere('url', 'like', '%fb.watch%');
                } elseif ($plat === 'instagram') {
                    $q->orWhere('url', 'like', '%instagram.com%');
                } elseif ($plat === 'tiktok') {
                    $q->orWhere('url', 'like', '%tiktok.com%');
                } elseif ($plat === 'web') {
                    $q->orWhere('raw_data->platform', 'web')
                      ->orWhere('raw_data->platform', 'news')
                      ->orWhere('raw_data->platform', 'rss');
                }

                $q->orWhereHas('source', fn($sq) => $sq->where('type', $plat));
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

    /**
     * Re-analyze sentiment for an article and its associated comments using custom rubric.
     */
    public function reanalyzeSentiment(Request $request, int $id, \App\Services\GeminiAnalyticsService $geminiService)
    {
        $article = Article::with(['source', 'keywords', 'comments'])->find($id);

        if (!$article) {
            return $this->error(__('messages.not_found'), 404);
        }

        $rubric = $request->input('sentiment_rubric');
        if (empty($rubric)) {
            $rubric = \App\Support\TenantContext::getTenant()?->getSentimentRubric();
        }

        // 1. Re-analyze article itself
        $articleText = trim(($article->title ?? '') . "\n" . ($article->summary ?? '') . "\n" . ($article->content ?? ''));
        $classifiedArticle = $geminiService->classifyPostsSentiment([
            [
                'id' => (string) $article->id,
                'text' => $articleText,
            ]
        ], $rubric);

        if (!empty($classifiedArticle[(string) $article->id])) {
            $res = $classifiedArticle[(string) $article->id];
            $article->sentiment = $res['sentiment'];
            $article->sentiment_score = $res['sentiment_score'];
            $raw = $article->raw_data ?? [];
            $raw['sentiment_reason'] = $res['reason'] ?? null;
            if (!empty($rubric)) {
                $raw['sentiment_rubric_used'] = $rubric;
            }
            $article->raw_data = $raw;
            $article->save();
        }

        // 2. Re-analyze comments if any
        if ($article->comments->isNotEmpty()) {
            $commentsToClassify = [];
            foreach ($article->comments as $comment) {
                $commentsToClassify[] = [
                    'id' => (string) $comment->id,
                    'text' => $comment->comment_text,
                ];
            }

            $classifiedComments = $geminiService->classifyPostsSentiment($commentsToClassify, $rubric);
            foreach ($article->comments as $comment) {
                if (isset($classifiedComments[(string) $comment->id])) {
                    $cRes = $classifiedComments[(string) $comment->id];
                    $cRaw = $comment->raw_data ?? [];
                    $cRaw['sentiment_reason'] = $cRes['reason'] ?? null;
                    if (!empty($rubric)) {
                        $cRaw['sentiment_rubric_used'] = $rubric;
                    }

                    $comment->update([
                        'sentiment' => $cRes['sentiment'],
                        'sentiment_score' => $cRes['sentiment_score'],
                        'raw_data' => $cRaw,
                    ]);
                }
            }
        }

        return $this->success(
            new ArticleResource($article->fresh(['source', 'keywords', 'comments'])),
            'Sentiment re-analyzed successfully against specified rubric.'
        );
    }
}
