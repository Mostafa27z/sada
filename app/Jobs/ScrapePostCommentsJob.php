<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\Tenant;
use App\Services\AiScraperService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScrapePostCommentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(
        public int $tenantId,
        public array $urlsByPlatform,
        public ?int $articleId = null,
        public mixed $commentsLimit = 50,
        public ?int $collectionId = null
    ) {}

    public function handle(AiScraperService $aiService): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (!$tenant) {
            return;
        }

        TenantContext::setTenant($tenant);

        $result = $aiService->scrapePostComments($this->urlsByPlatform, $this->commentsLimit);

        if (($result['status'] ?? '') !== 'success') {
            Log::warning("ScrapePostCommentsJob failed for tenant {$this->tenantId}", ['result' => $result]);
            $isFailed = ($result['status'] ?? '') === 'failed' || ($result['error_code'] ?? '') === 'privacy_restricted';
            $errorMsg = $result['error_message'] ?? ($isFailed ? 'المنشور خاص أو ضمن مجموعة مغلقة تمنع سياسات الخصوصية الوصول إليها.' : null);

            if ($this->articleId) {
                $article = Article::withoutGlobalScopes()->find($this->articleId);
                if ($article) {
                    $raw = $article->raw_data ?? [];
                    if ($errorMsg) {
                        $raw['error_message'] = $errorMsg;
                    }
                    if (!empty($result['error_code'])) {
                        $raw['error_code'] = $result['error_code'];
                    }
                    $article->raw_data = $raw;
                    $article->save();
                }
            }

            if ($this->collectionId) {
                Collection::where('id', $this->collectionId)->update([
                    'status' => $isFailed ? Collection::STATUS_FAILED : Collection::STATUS_COMPLETED,
                    'error_message' => $errorMsg,
                ]);
            }
            TenantContext::forgetTenant();
            return;
        }

        // 1. Update Post-Level Metadata (Engagement, Reach, Likes) on the Article
        if ($this->articleId && !empty($result['post_metadata'])) {
            $article = Article::withoutGlobalScopes()->find($this->articleId);
            if ($article) {
                $raw = $article->raw_data ?? [];
                $article->raw_data = array_merge($raw, $result['post_metadata']);
                $article->save();
            }
        }

        $commentsByPlatform = $result['comments_by_platform'] ?? [];
        $normalizedComments = [];

        foreach ($commentsByPlatform as $platformKey => $comments) {
            $platformName = match ($platformKey) {
                'instagram_comments' => 'instagram',
                'facebook_comments' => 'facebook',
                'tiktok_comments' => 'tiktok',
                'twitter_comments' => 'x',
                default => 'other',
            };

            foreach ($comments as $commentItem) {
                if (is_array($commentItem)) {
                    $author = trim($commentItem['author'] ?? 'مستخدم');
                    $text = trim($commentItem['text'] ?? '');
                    $sentiment = $commentItem['sentiment'] ?? 'positive';
                    $sentimentScore = $commentItem['sentiment_score'] ?? null;
                    $createdAt = !empty($commentItem['comment_created_at'])
                        ? $commentItem['comment_created_at']
                        : (!empty($commentItem['created_at']) ? $commentItem['created_at'] : now());
                    $externalId = !empty($commentItem['external_id']) ? (string) $commentItem['external_id'] : null;
                    $parentExternalId = !empty($commentItem['parent_external_id']) ? (string) $commentItem['parent_external_id'] : null;
                    $likesCount = (int) ($commentItem['likes_count'] ?? 0);
                } else {
                    $commentStr = (string) $commentItem;
                    $parts = explode(":\n\n", $commentStr, 2);
                    $author = trim(ltrim($parts[0] ?? 'مستخدم', '@'));
                    $text = trim($parts[1] ?? $commentStr);
                    $sentiment = 'positive';
                    if (str_contains($text, 'ملاحظات') || str_contains($text, 'توضيح') || str_contains($text, 'مشكلة') || str_contains($text, 'تحسين')) {
                        $sentiment = 'neutral';
                    }
                    $sentimentScore = null;
                    $createdAt = now();
                    $externalId = null;
                    $parentExternalId = null;
                    $likesCount = 0;
                }

                if (empty($text)) {
                    continue;
                }

                $normalizedComments[] = [
                    'platform' => $platformName,
                    'author' => $author,
                    'text' => $text,
                    'sentiment' => $sentiment,
                    'sentiment_score' => $sentimentScore,
                    'external_id' => $externalId,
                    'parent_external_id' => $parentExternalId,
                    'likes_count' => $likesCount,
                    'comment_created_at' => $createdAt,
                ];
            }
        }

        // 2. Two-pass insertion: First insert parent comments, then link child replies
        $externalIdToModelId = [];

        // Pass A: Parent comments (no parent_external_id)
        foreach ($normalizedComments as $c) {
            if (!empty($c['parent_external_id'])) {
                continue;
            }

            $matchAttributes = !empty($c['external_id'])
                ? [
                    'tenant_id' => $this->tenantId,
                    'article_id' => $this->articleId,
                    'external_id' => $c['external_id'],
                ]
                : [
                    'tenant_id' => $this->tenantId,
                    'article_id' => $this->articleId,
                    'author' => $c['author'],
                    'comment_text' => $c['text'],
                ];

            $model = Comment::updateOrCreate(
                $matchAttributes,
                [
                    'author' => $c['author'],
                    'comment_text' => $c['text'],
                    'platform' => $c['platform'],
                    'external_id' => $c['external_id'],
                    'parent_external_id' => null,
                    'parent_id' => null,
                    'sentiment' => $c['sentiment'],
                    'sentiment_score' => $c['sentiment_score'],
                    'likes_count' => $c['likes_count'],
                    'comment_created_at' => $c['comment_created_at'],
                    'raw_data' => [
                        'author' => $c['author'],
                        'text' => $c['text'],
                        'sentiment' => $c['sentiment'],
                        'analytics' => $result['analytics'] ?? [],
                    ],
                    'created_at' => $c['comment_created_at'],
                ]
            );

            if (!empty($c['external_id'])) {
                $externalIdToModelId[$c['external_id']] = $model->id;
            }
            if (!empty($c['raw_data']['comment_id'])) {
                $externalIdToModelId[(string)$c['raw_data']['comment_id']] = $model->id;
            }
            if (!empty($c['raw_data']['base64_id'])) {
                $externalIdToModelId[(string)$c['raw_data']['base64_id']] = $model->id;
            }
            if (!empty($c['raw_data']['cid'])) {
                $externalIdToModelId[(string)$c['raw_data']['cid']] = $model->id;
            }
            if (!empty($c['raw_data']['tweet_id'])) {
                $externalIdToModelId[(string)$c['raw_data']['tweet_id']] = $model->id;
            }
        }

        // Pass B: Child replies (have parent_external_id)
        foreach ($normalizedComments as $c) {
            if (empty($c['parent_external_id'])) {
                continue;
            }

            $parentId = $externalIdToModelId[$c['parent_external_id']] ?? null;
            if (!$parentId && $this->articleId) {
                $parentInDb = Comment::where('tenant_id', $this->tenantId)
                    ->where('article_id', $this->articleId)
                    ->where(function($q) use ($c) {
                        $q->where('external_id', $c['parent_external_id'])
                          ->orWhere('raw_data->comment_id', $c['parent_external_id'])
                          ->orWhere('raw_data->base64_id', $c['parent_external_id'])
                          ->orWhere('raw_data->cid', $c['parent_external_id'])
                          ->orWhere('raw_data->tweet_id', $c['parent_external_id']);
                    })
                    ->first();
                $parentId = $parentInDb?->id;
            }

            $childMatch = !empty($c['external_id'])
                ? [
                    'tenant_id' => $this->tenantId,
                    'article_id' => $this->articleId,
                    'external_id' => $c['external_id'],
                ]
                : [
                    'tenant_id' => $this->tenantId,
                    'article_id' => $this->articleId,
                    'author' => $c['author'],
                    'comment_text' => $c['text'],
                ];

            $child = Comment::updateOrCreate(
                $childMatch,
                [
                    'author' => $c['author'],
                    'comment_text' => $c['text'],
                    'platform' => $c['platform'],
                    'external_id' => $c['external_id'],
                    'parent_external_id' => $c['parent_external_id'],
                    'parent_id' => $parentId,
                    'sentiment' => $c['sentiment'],
                    'sentiment_score' => $c['sentiment_score'],
                    'likes_count' => $c['likes_count'],
                    'comment_created_at' => $c['comment_created_at'],
                    'raw_data' => $c['raw_data'] ?? [
                        'author' => $c['author'],
                        'text' => $c['text'],
                        'sentiment' => $c['sentiment'],
                    ],
                    'created_at' => $c['comment_created_at'],
                ]
            );

            if (!empty($c['external_id'])) {
                $externalIdToModelId[$c['external_id']] = $child->id;
            }
            if (!empty($c['raw_data']['cid'])) {
                $externalIdToModelId[(string)$c['raw_data']['cid']] = $child->id;
            }
        }

        // Synchronize exact replies_count for all parent comments
        if ($this->articleId) {
            $parents = Comment::where('tenant_id', $this->tenantId)
                ->where('article_id', $this->articleId)
                ->whereNull('parent_id')
                ->get();

            foreach ($parents as $p) {
                $actualCount = Comment::where('parent_id', $p->id)->count();
                if ($p->replies_count !== $actualCount) {
                    $p->update(['replies_count' => $actualCount]);
                }
            }
        }

        if ($this->collectionId) {
            Collection::where('id', $this->collectionId)->update([
                'status' => Collection::STATUS_COMPLETED,
                'error_message' => null,
            ]);
        }

        TenantContext::forgetTenant();
    }
}
