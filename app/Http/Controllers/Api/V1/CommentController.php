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
            'url' => ['sometimes', 'nullable', 'string'],
            'insta_urls' => ['sometimes', 'array'],
            'facebook_urls' => ['sometimes', 'array'],
            'tiktok_urls' => ['sometimes', 'array'],
            'twitter_urls' => ['sometimes', 'array'],
            'article_id' => ['sometimes', 'nullable', 'exists:articles,id'],
            'sentiment_rubric' => ['sometimes', 'nullable', 'array'],
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

        if ($request->filled('url')) {
            $singleUrl = trim((string) $request->input('url'));
            if (str_contains($singleUrl, 'instagram')) {
                $urlsByPlatform['insta_urls'][] = $singleUrl;
            } elseif (str_contains($singleUrl, 'facebook') || str_contains($singleUrl, 'fb.watch')) {
                $urlsByPlatform['facebook_urls'][] = $singleUrl;
            } elseif (str_contains($singleUrl, 'tiktok')) {
                $urlsByPlatform['tiktok_urls'][] = $singleUrl;
            } else {
                $urlsByPlatform['twitter_urls'][] = $singleUrl;
            }
        }

        ScrapePostCommentsJob::dispatch(
            $tenantId,
            $urlsByPlatform,
            $request->get('article_id'),
            50,
            null,
            $request->get('sentiment_rubric')
        );

        return $this->success(null, 'Scraping task queued successfully. Results will be processed asynchronously.', 202);
    }

    /**
     * Generate tactical AI recommendations and post draft for a comment or monitored post.
     */
    public function generateAiRecommendation(Request $request, \App\Services\GeminiAnalyticsService $geminiService)
    {
        $request->validate([
            'text' => ['required', 'string'],
            'author' => ['sometimes', 'nullable', 'string'],
            'platform' => ['sometimes', 'nullable', 'string'],
            'sentiment' => ['sometimes', 'nullable', 'string'],
        ]);

        $result = $geminiService->generatePostRecommendation(
            $request->input('text'),
            $request->input('author'),
            $request->input('platform'),
            $request->input('sentiment')
        );

        return $this->success($result, 'AI recommendation generated successfully.');
    }

    /**
     * Re-analyze sentiment and explainability reason for a single comment in the context of its parent post and entity rubric.
     */
    public function reanalyzeSentiment(
        Request $request,
        int $id,
        \App\Services\GeminiAnalyticsService $geminiService,
        \App\Services\AiScraperService $aiScraperService
    ) {
        $comment = Comment::with(['article.collections'])->find($id);
        if (!$comment) {
            return $this->error(__('messages.not_found'), 404);
        }

        $tenant = TenantContext::getTenant();
        $rubric = $request->input('sentiment_rubric');
        if (empty($rubric)) {
            $firstCol = $comment->article?->collections?->first();
            $rubric = $firstCol ? $firstCol->getEffectiveRubric() : $tenant?->getSentimentRubric();
        }
        $rubric = is_array($rubric) ? $rubric : [];
        if (empty($rubric['preferred_side']) && !empty($tenant?->name)) {
            $rubric['preferred_side'] = $tenant->name;
        }
        if (empty($rubric['topic_context']) && !empty($tenant?->name)) {
            $rubric['topic_context'] = $tenant->name;
        }

        $article = $comment->article;
        $postContext = $article
            ? trim(($article->title ?? '') . "\n" . ($article->summary ?? '') . "\n" . ($article->content ?? ''))
            : '';

        $classified = $geminiService->classifyPostsSentiment(
            [['id' => (string) $comment->id, 'text' => (string) $comment->comment_text]],
            $rubric,
            null,
            $postContext !== '' ? $postContext : null
        );

        if (!empty($classified[(string) $comment->id])) {
            $res = $classified[(string) $comment->id];
        } else {
            $heur = $aiScraperService->classifyArabicSentiment(
                (string) $comment->comment_text,
                $rubric,
                null,
                $postContext !== '' ? $postContext : null
            );
            $res = [
                'sentiment' => $heur['sentiment'] ?? 'neutral',
                'sentiment_score' => $heur['score'] ?? 0.85,
                'reason' => $heur['reason'] ?? 'تم التصنيف وفق قواعد الموقف لصالح المنشأة',
                'is_relevant' => true,
            ];
        }

        $raw = $comment->raw_data ?? [];
        $raw['sentiment'] = $res['sentiment'];
        $raw['sentiment_reason'] = $res['reason'] ?? null;
        $raw['out_of_scope'] = ($res['is_relevant'] ?? true) === false;

        $comment->update([
            'sentiment' => $res['sentiment'],
            'sentiment_score' => $res['sentiment_score'] ?? 0.85,
            'raw_data' => $raw,
        ]);

        return $this->success([
            'id' => $comment->id,
            'sentiment' => $comment->sentiment,
            'sentiment_score' => $comment->sentiment_score,
            'sentiment_reason' => $raw['sentiment_reason'],
        ], 'تمت إعادة تحليل المشاعر بنجاح.');
    }

    /**
     * Manually override sentiment for a single comment for maximum operator control.
     */
    public function updateSentiment(Request $request, int $id)
    {
        $validated = $request->validate([
            'sentiment' => ['required', 'in:positive,neutral,negative'],
        ]);

        $comment = Comment::with('article.comments')->find($id);
        if (!$comment) {
            return $this->error(__('messages.not_found'), 404);
        }

        $sentiment = $validated['sentiment'];
        $raw = $comment->raw_data ?? [];
        $raw['sentiment'] = $sentiment;
        $raw['sentiment_reason'] = 'تم ضبط وتصنيف المشاعر يدوياً بواسطة إدارة المنشأة';
        $raw['manual_override'] = true;

        $comment->update([
            'sentiment' => $sentiment,
            'sentiment_score' => 1.0,
            'raw_data' => $raw,
        ]);

        // Refresh parent article aggregate comment stats
        if ($comment->article) {
            $article = $comment->article;
            $freshComments = $article->comments()->get();
            $counts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
            $inScope = 0;
            foreach ($freshComments as $fc) {
                if (!empty($fc->raw_data['out_of_scope'])) {
                    continue;
                }
                $s = $fc->sentiment ?: 'neutral';
                $counts[$s] = ($counts[$s] ?? 0) + 1;
                $inScope++;
            }
            $artRaw = $article->raw_data ?? [];
            $artRaw['analytics'] = array_merge($artRaw['analytics'] ?? [], [
                'total_comments' => $inScope,
                'sentiment_counts' => $counts,
            ]);
            $article->raw_data = $artRaw;
            $article->save();
        }

        return $this->success([
            'id' => $comment->id,
            'sentiment' => $comment->sentiment,
            'sentiment_score' => $comment->sentiment_score,
            'sentiment_reason' => $raw['sentiment_reason'],
        ], 'تم تحديث التصنيف يدوياً بنجاح.');
    }
}
