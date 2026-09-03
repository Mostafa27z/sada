<?php

namespace App\Jobs;

use App\Models\Article;
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
        public ?int $articleId = null
    ) {}

    public function handle(AiScraperService $aiService): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (!$tenant) {
            return;
        }

        TenantContext::setTenant($tenant);

        $result = $aiService->scrapePostComments($this->urlsByPlatform);

        if (($result['status'] ?? '') !== 'success') {
            Log::warning("ScrapePostCommentsJob failed for tenant {$this->tenantId}", ['result' => $result]);
            TenantContext::forgetTenant();
            return;
        }

        $commentsByPlatform = $result['comments_by_platform'] ?? [];

        foreach ($commentsByPlatform as $platformKey => $comments) {
            $platformName = match ($platformKey) {
                'instagram_comments' => 'instagram',
                'facebook_comments' => 'facebook',
                'tiktok_comments' => 'tiktok',
                'twitter_comments' => 'x',
                default => 'other',
            };

            foreach ($comments as $commentStr) {
                $parts = explode(":\n\n", $commentStr, 2);
                $author = trim($parts[0] ?? 'Unknown User');
                $text = trim($parts[1] ?? $commentStr);

                Comment::create([
                    'tenant_id' => $this->tenantId,
                    'article_id' => $this->articleId,
                    'platform' => $platformName,
                    'author' => $author,
                    'comment_text' => $text,
                    'raw_data' => [
                        'raw_comment' => $commentStr,
                        'analytics' => $result['analytics'] ?? [],
                    ],
                ]);
            }
        }

        TenantContext::forgetTenant();
    }
}
