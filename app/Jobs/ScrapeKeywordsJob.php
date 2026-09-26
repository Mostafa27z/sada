<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Collection;
use App\Models\Tenant;
use App\Services\AiScraperService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScrapeKeywordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(
        public int $tenantId,
        public array $keywords,
        public array $platforms = ['instagram', 'facebook', 'x', 'tiktok'],
        public ?string $country = null,
        public ?int $collectionId = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public int $limit = 50
    ) {}

    public function handle(AiScraperService $aiService): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (!$tenant) {
            return;
        }

        TenantContext::setTenant($tenant);

        $result = $aiService->scrapeByKeywords(
            $this->keywords,
            $this->platforms,
            $this->country,
            $this->dateFrom,
            $this->dateTo,
            $this->limit
        );

        if (($result['status'] ?? '') !== 'success') {
            Log::warning("ScrapeKeywordsJob failed for tenant {$this->tenantId}", ['result' => $result]);
            if ($this->collectionId) {
                Collection::where('id', $this->collectionId)->update([
                    'status' => Collection::STATUS_FAILED,
                    'error_message' => $result['error_message'] ?? 'تعذر العثور على نتائج للكلمات المفتاحية المطلوبة.',
                ]);
            }
            TenantContext::forgetTenant();
            return;
        }

        $postsByPlatform = $result['posts_by_platform'] ?? [];
        $syncedArticleIds = [];

        $fromTs = $this->dateFrom ? strtotime($this->dateFrom . ' 00:00:00') : null;
        $toTs = $this->dateTo ? strtotime($this->dateTo . ' 23:59:59') : null;
        $cutoffTime = time() - (30 * 86400);

        foreach ($postsByPlatform as $platformKey => $posts) {
            $platformName = match ($platformKey) {
                'instagram_posts' => 'instagram',
                'facebook_posts' => 'facebook',
                'x_posts' => 'x',
                'tiktok_posts' => 'tiktok',
                default => 'web',
            };

            foreach ($posts as $post) {
                if (is_array($post)) {
                    $content = trim($post['text'] ?? '');
                    // Discard posts with empty, whitespace, or placeholder "Unknown" text
                    if (empty($content) || mb_strlen($content) < 5 || strtolower($content) === 'unknown') {
                        continue;
                    }

                    $postId = $post['post_id'] ?? null;
                    $externalId = $post['external_id'] ?? ($postId ? "{$platformName}_{$postId}" : 'ext_' . md5(json_encode($post)));
                    
                    $rawAuthor = trim($post['author'] ?? '');
                    if (empty($rawAuthor) || strtolower($rawAuthor) === 'unknown') {
                        $author = $platformName === 'web' ? 'مصدر إخباري' : 'مستخدم ' . ucfirst($platformName);
                    } else {
                        $author = mb_substr($rawAuthor, 0, 250);
                    }

                    $rawTitle = trim($post['title'] ?? '');
                    if (empty($rawTitle) || strtolower($rawTitle) === 'unknown') {
                        $title = mb_substr($content, 0, 200);
                    } else {
                        $title = mb_substr($rawTitle, 0, 200);
                    }

                    $url = !empty($post['url']) ? $post['url'] : ('https://' . ($platformName === 'web' ? 'news.google.com' : $platformName . '.com'));
                    $countryCode = mb_substr($post['country'] ?? $this->country ?? 'SA', 0, 10);
                    $rawCreatedAt = $post['created_at'] ?? null;

                    if (!empty($rawCreatedAt)) {
                        $parsedTime = strtotime($rawCreatedAt);
                        if ($fromTs && $parsedTime && $parsedTime < $fromTs) {
                            continue; // Skip posts published before date_from
                        }
                        if ($toTs && $parsedTime && $parsedTime > $toTs) {
                            continue; // Skip posts published after date_to
                        }
                        if (!$fromTs && $parsedTime && $parsedTime < $cutoffTime) {
                            continue;
                        }
                        $createdAt = $parsedTime ? date('Y-m-d H:i:s', $parsedTime) : now();
                    } else {
                        $createdAt = now();
                    }
                } else {
                    $lines = explode("\n", trim((string)$post));
                    $rawAuthor = trim($lines[0] ?? 'مصدر إخباري');
                    $author = (empty($rawAuthor) || strtolower($rawAuthor) === 'unknown') ? 'مصدر إخباري' : mb_substr($rawAuthor, 0, 250);
                    $content = trim(implode("\n", array_slice($lines, 1)));
                    if (empty($content) || mb_strlen($content) < 5 || strtolower($content) === 'unknown') {
                        continue;
                    }
                    $title = mb_substr($content ?: $author, 0, 200);

                    $postId = md5((string)$post);
                    $externalId = "{$platformName}_{$postId}";
                    $url = 'https://news.google.com';
                    $countryCode = mb_substr($this->country ?? 'SA', 0, 10);
                    $createdAt = now();
                }

                $article = Article::withoutGlobalScopes()
                    ->withTrashed()
                    ->firstOrNew([
                        'tenant_id' => $this->tenantId,
                        'external_id' => mb_substr($externalId, 0, 190),
                    ]);

                if ($article->trashed()) {
                    $article->restore();
                }

                $postSentiment = is_array($post) ? ($post['sentiment'] ?? null) : null;
                $sentiment = in_array($postSentiment, ['positive', 'negative', 'neutral']) ? $postSentiment : 'neutral';
                $sentimentScore = (is_array($post) && isset($post['sentiment_score'])) ? floatval($post['sentiment_score']) : 0.75;

                $article->fill([
                    'title' => $title ?: 'خبر / منشور صحفي',
                    'slug' => $article->slug ?: (Str::slug(mb_substr($title, 0, 40)) . '-' . Str::random(8)),
                    'content' => $content,
                    'summary' => mb_substr($content, 0, 300),
                    'author' => $author,
                    'url' => $url,
                    'language' => 'ar',
                    'country' => $countryCode,
                    'published_at' => $createdAt,
                    'sentiment' => $sentiment,
                    'sentiment_score' => $sentimentScore,
                    'raw_data' => [
                        'platform' => $platformName,
                        'reach' => (is_array($post) ? ($post['reach'] ?? null) : null),
                        'engagement' => (is_array($post) ? ($post['engagement'] ?? null) : null),
                        'raw_post' => $post,
                        'analytics' => $result['analytics'] ?? [],
                    ],
                ]);
                $article->save();

                $syncedArticleIds[] = $article->id;


                if (is_array($post) && !empty($post['keyword'])) {
                    $kwRecord = \App\Models\Keyword::firstOrCreate(
                        ['tenant_id' => $this->tenantId, 'name' => $post['keyword']],
                        ['status' => 'active', 'category' => 'general']
                    );
                    if ($kwRecord) {
                        $article->keywords()->syncWithoutDetaching([
                            $kwRecord->id => [
                                'matched_terms' => json_encode([$post['keyword']]),
                                'relevance_score' => 0.90,
                            ],
                        ]);
                    }
                }
            }
        }

        if ($this->collectionId) {
            $colRecord = Collection::withoutGlobalScopes()->find($this->collectionId);
            if ($colRecord) {
                if (!empty($syncedArticleIds)) {
                    $colRecord->articles()->sync($syncedArticleIds);
                }
                $colRecord->update([
                    'status' => Collection::STATUS_COMPLETED,
                    'error_message' => null,
                ]);

                try {
                    app(\App\Services\ReportGeneratorService::class)->generateCampaignReport($colRecord);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Failed to auto-generate keyword campaign PDF report: " . $e->getMessage());
                }
            }
        }

        TenantContext::forgetTenant();
    }
}
