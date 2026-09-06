<?php

namespace App\Jobs;

use App\Models\Article;
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
        public ?string $country = null
    ) {}

    public function handle(AiScraperService $aiService): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (!$tenant) {
            return;
        }

        TenantContext::setTenant($tenant);

        $result = $aiService->scrapeByKeywords($this->keywords, $this->platforms, $this->country);

        if (($result['status'] ?? '') !== 'success') {
            Log::warning("ScrapeKeywordsJob failed for tenant {$this->tenantId}", ['result' => $result]);
            TenantContext::forgetTenant();
            return;
        }

        $postsByPlatform = $result['posts_by_platform'] ?? [];

        foreach ($postsByPlatform as $platformKey => $posts) {
            $platformName = match ($platformKey) {
                'instagram_posts' => 'instagram',
                'facebook_posts' => 'facebook',
                'x_posts' => 'x',
                'tiktok_posts' => 'tiktok',
                default => 'other',
            };

            foreach ($posts as $post) {
                if (is_array($post)) {
                    $postId = $post['post_id'] ?? null;
                    $externalId = $post['external_id'] ?? ($postId ? "{$platformName}_{$postId}" : 'ext_' . md5(json_encode($post)));
                    $author = $post['author'] ?? 'Unknown User';
                    $content = $post['text'] ?? '';
                    $url = $post['url'] ?? ('https://' . $platformName . '.com/post/' . ($postId ?: md5($content)));
                    $countryCode = mb_substr($post['country'] ?? $this->country ?? 'SA', 0, 10);
                    $createdAt = !empty($post['created_at']) ? date('Y-m-d H:i:s', strtotime($post['created_at'])) : now();
                } else {
                    $lines = explode("\n", trim((string)$post));
                    $author = trim($lines[0] ?? 'Unknown');
                    $content = trim(implode("\n", array_slice($lines, 1)));
                    $postId = md5((string)$post);
                    $externalId = "{$platformName}_{$postId}";
                    $url = 'https://' . $platformName . '.com/post/' . $postId;
                    $countryCode = mb_substr($this->country ?? 'SA', 0, 10);
                    $createdAt = now();
                }

                $title = mb_substr($content ?: $author, 0, 100);

                Article::firstOrCreate(
                    [
                        'tenant_id' => $this->tenantId,
                        'external_id' => $externalId,
                    ],
                    [
                        'title' => $title ?: 'Social Post',
                        'slug' => Str::slug(mb_substr($title, 0, 50)) . '-' . Str::random(6),
                        'content' => $content,
                        'summary' => mb_substr($content, 0, 200),
                        'author' => $author,
                        'url' => $url,
                        'language' => 'ar',
                        'country' => $countryCode,
                        'published_at' => $createdAt,
                        'raw_data' => [
                            'platform' => $platformName,
                            'raw_post' => $post,
                            'analytics' => $result['analytics'] ?? [],
                        ],
                    ]
                );
            }
        }

        TenantContext::forgetTenant();
    }
}
