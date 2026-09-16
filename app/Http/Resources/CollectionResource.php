<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $articles = $this->articles ?? collect();
        $allComments = $articles->flatMap(fn($a) => $a->comments);

        $isKeyword = empty($this->link) || $this->link === '#' || !empty($this->keywords) || str_starts_with($this->name, 'رصد كلمة:') || str_starts_with($this->name, 'كلمة:');
        $keyword = null;
        if (!empty($this->keywords)) {
            $keyword = is_array($this->keywords) ? implode(', ', $this->keywords) : $this->keywords;
        } elseif (preg_match('/(?:رصد\s+)?كلمة:\s*"?([^"]+)"?/u', $this->name, $matches)) {
            $keyword = trim($matches[1]);
        } elseif ($isKeyword && (str_starts_with($this->name, 'رصد كلمة:') || str_starts_with($this->name, 'كلمة:'))) {
            $keyword = $this->name;
        }

        // Filter articles to match collection's selected country and sort by newest first
        if (!empty($this->country) && $this->country !== 'ALL') {
            $articles = $articles->filter(function ($a) {
                return empty($a->country) || $a->country === $this->country || $a->country === 'ALL';
            });
        }

        // Sort chronologically descending so newest news/posts appear first
        $articles = $articles->sortByDesc(function ($a) {
            return $a->published_at ? strtotime($a->published_at) : ($a->created_at ? strtotime($a->created_at) : 0);
        })->values();

        $allComments = $articles->flatMap(fn($a) => $a->comments);

        if ($allComments->isNotEmpty()) {
            $positive = $allComments->where('sentiment', 'positive')->count();
            $neutral = $allComments->where('sentiment', 'neutral')->count();
            $negative = $allComments->where('sentiment', 'negative')->count();
        } else {
            $positive = $articles->where('sentiment', 'positive')->count();
            $neutral = $articles->where('sentiment', 'neutral')->count();
            $negative = $articles->where('sentiment', 'negative')->count();
        }

        $articlesCount = $articles->count();
        $commentsCount = $allComments->count();
        $resultsCount = $isKeyword ? ($articlesCount ?: $commentsCount) : ($commentsCount ?: $articlesCount);

        $rawPlats = !empty($this->platform) ? explode(',', $this->platform) : [];
        $platformsList = array_values(array_filter(array_map('trim', $rawPlats)));

        $repliesByParent = $allComments->whereNotNull('parent_id')->groupBy('parent_id');
        $parentComments = $allComments->filter(fn($c) => empty($c->parent_id))->map(function ($parent) use ($repliesByParent) {
            $childReplies = $repliesByParent->get($parent->id, collect())->sortBy('comment_created_at')->values();
            $parent->setRelation('replies', $childReplies);
            return $parent;
        })->values();

        $primaryArticle = $articles->first();
        $errorMessage = $this->error_message ?? $primaryArticle?->raw_data['error_message'] ?? null;
        if (empty($errorMessage) && $this->status === 'failed') {
            $errorMessage = 'تعذر رصد التعليقات: الرابط خاص أو ضمن مجموعة مغلقة تمنع سياسات الخصوصية الوصول إليها.';
        }
        $engagement = $primaryArticle?->raw_data['engagement'] ?? null;
        if ($isKeyword && $articles->isNotEmpty()) {
            $totalEng = 0;
            foreach ($articles as $art) {
                $e = $art->raw_data['engagement'] ?? 0;
                $totalEng += (int) preg_replace('/[^\d]/', '', (string)$e);
            }
            if ($totalEng > 0) {
                $engagement = number_format($totalEng) . ' تفاعل';
            }
        } elseif (empty($engagement)) {
            if (!empty($primaryArticle?->raw_data['likes'])) {
                $engagement = number_format((int)$primaryArticle->raw_data['likes']) . ' تفاعل';
            } else {
                $totalCommentLikes = (int) $allComments->sum('likes_count');
                $totalComments = (int) $allComments->count();
                $realTotal = $totalComments + $totalCommentLikes;
                if ($realTotal > 0) {
                    $engagement = number_format($realTotal) . ' تفاعل';
                }
            }
        }
        $reach = $primaryArticle?->raw_data['reach'] ?? null;
        if (empty($reach) && !empty($primaryArticle?->raw_data['views'])) {
            $reach = number_format((int)$primaryArticle->raw_data['views']);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'link' => $isKeyword ? '' : ($this->link ?: ''),
            'platform' => $this->platform,
            'platforms' => !empty($platformsList) ? $platformsList : ['x'],
            'country' => $this->country,
            'comments_limit' => (string) ($this->comments_limit ?: '50'),
            'color' => $this->color,
            'status' => $this->status ?: ($resultsCount > 0 ? 'completed' : 'pending'),
            'error_message' => $errorMessage,
            'type' => $isKeyword ? 'keyword' : 'campaign',
            'keyword' => $keyword,
            'reach' => $reach,
            'engagement' => $engagement,
            'results_count' => $resultsCount,
            'articles_count' => $articlesCount,
            'comments_count' => $commentsCount,
            'positive_count' => $positive,
            'neutral_count' => $neutral,
            'negative_count' => $negative,
            'comments' => CommentResource::collection($parentComments->isNotEmpty() ? $parentComments : $allComments),
            'articles' => ArticleResource::collection($articles),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
