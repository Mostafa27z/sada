<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Keyword;
use App\Models\WebhookLog;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class ProcessIngestedArticleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $webhookLogId,
        protected int $tenantId,
        protected array $articleData
    ) {
    }

    public function handle(): void
    {
        $log = WebhookLog::find($this->webhookLogId);

        try {
            // Store article under tenant context
            $article = TenantContext::withoutTenancy(function () {
                return Article::create([
                    'tenant_id' => $this->tenantId,
                    'source_id' => $this->articleData['source_id'] ?? null,
                    'external_id' => $this->articleData['external_id'] ?? Str::uuid()->toString(),
                    'title' => $this->articleData['title'],
                    'slug' => Str::slug($this->articleData['title']) . '-' . Str::random(5),
                    'content' => $this->articleData['content'] ?? null,
                    'summary' => $this->articleData['summary'] ?? null,
                    'url' => $this->articleData['url'],
                    'image_url' => $this->articleData['image_url'] ?? null,
                    'author' => $this->articleData['author'] ?? null,
                    'language' => $this->articleData['language'] ?? 'ar',
                    'country' => $this->articleData['country'] ?? 'SA',
                    'category' => $this->articleData['category'] ?? null,
                    'published_at' => $this->articleData['published_at'] ?? now(),
                    'sentiment' => $this->articleData['sentiment'] ?? 'neutral',
                    'sentiment_score' => $this->articleData['sentiment_score'] ?? 0.5000,
                    'ai_metadata' => $this->articleData['ai_metadata'] ?? [],
                    'raw_data' => $this->articleData['raw_data'] ?? [],
                ]);
            });

            // Keyword Matching Algorithm
            $tenantKeywords = TenantContext::withoutTenancy(function () {
                return Keyword::where('tenant_id', $this->tenantId)
                    ->where('status', Keyword::STATUS_ACTIVE)
                    ->get();
            });

            $matchedKeywordIds = [];
            $textToMatch = mb_strtolower($article->title . ' ' . $article->content);

            foreach ($tenantKeywords as $keyword) {
                $term = mb_strtolower($keyword->name);
                if (str_contains($textToMatch, $term)) {
                    $matchedKeywordIds[$keyword->id] = [
                        'matched_terms' => json_encode([$keyword->name]),
                        'relevance_score' => 0.9500,
                    ];
                }
            }

            if (!empty($matchedKeywordIds)) {
                TenantContext::withoutTenancy(function () use ($article, $matchedKeywordIds) {
                    $article->keywords()->attach($matchedKeywordIds);
                });
            }

            // Update log status
            if ($log) {
                $log->update([
                    'status' => WebhookLog::STATUS_PROCESSED,
                    'processed_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            if ($log) {
                $log->update([
                    'status' => WebhookLog::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}
