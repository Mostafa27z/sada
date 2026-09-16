<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeKeywordsJob;
use App\Jobs\ScrapePostCommentsJob;
use App\Models\Article;
use App\Models\Collection;
use App\Models\Keyword;
use App\Models\Source;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class SyncCollectionsAndKeywordsCommand extends Command
{
    protected $signature = 'sada:sync-all';
    protected $description = 'Run scheduled 4-hour synchronization for active campaign links and keywords';

    public function handle(): int
    {
        $tenants = Tenant::whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get();

        if ($tenants->isEmpty()) {
            $this->info('No active tenants found.');
            return Command::SUCCESS;
        }

        $totalLinksDispatched = 0;
        $totalKeywordsDispatched = 0;

        foreach ($tenants as $tenant) {
            TenantContext::setTenant($tenant);

            // 1. Sync all active Link Collections for this tenant
            $linkCollections = Collection::where('tenant_id', $tenant->id)
                ->whereNotNull('link')
                ->where('link', '!=', '')
                ->where('link', '!=', '#')
                ->get();

            foreach ($linkCollections as $collection) {
                try {
                    $platform = $collection->platform ?: 'web';
                    $source = Source::firstOrCreate(
                        ['tenant_id' => $tenant->id, 'name' => ucfirst($platform)],
                        [
                            'url' => 'https://' . $platform . '.com',
                            'platform' => $platform,
                            'country' => $collection->country ?: 'SA',
                            'status' => 'active',
                        ]
                    );

                    $article = Article::firstOrCreate(
                        ['tenant_id' => $tenant->id, 'url' => $collection->link],
                        [
                            'source_id' => $source->id,
                            'title' => $collection->name,
                            'content' => $collection->description ?: $collection->name,
                            'summary' => $collection->name,
                            'country' => $collection->country ?: 'SA',
                            'sentiment' => 'positive',
                            'published_at' => now(),
                            'raw_data' => ['platform' => $platform],
                        ]
                    );

                    $collection->articles()->syncWithoutDetaching([$article->id]);
                    $collection->update(['status' => Collection::STATUS_PENDING]);

                    $commentsLimit = $collection->comments_limit ?? 50;
                    ScrapePostCommentsJob::dispatch(
                        $tenant->id,
                        [$collection->link],
                        $article->id,
                        $commentsLimit,
                        $collection->id
                    );

                    $totalLinksDispatched++;
                } catch (\Throwable $e) {
                    $this->error("Error syncing collection #{$collection->id}: " . $e->getMessage());
                }
            }

            // 2. Sync all active Keywords for this tenant
            $keywordRecords = Keyword::where('status', Keyword::STATUS_ACTIVE)->get();
            $keywords = [];

            foreach ($keywordRecords as $kw) {
                $keywords[] = $kw->name;
                $config = $kw->configuration;
                if (!empty($config['keywords']) && is_array($config['keywords'])) {
                    foreach ($config['keywords'] as $subKw) {
                        if (!empty($subKw) && !in_array($subKw, $keywords)) {
                            $keywords[] = $subKw;
                        }
                    }
                }
            }

            if (!empty($keywords)) {
                ScrapeKeywordsJob::dispatch($tenant->id, array_values(array_unique($keywords)));
                $totalKeywordsDispatched += count($keywords);
            }

            // 3. Sync keyword-type collections
            $keywordCollections = Collection::where('tenant_id', $tenant->id)
                ->where(function ($q) {
                    $q->whereNull('link')
                      ->orWhere('link', '')
                      ->orWhere('link', '#');
                })
                ->get();

            foreach ($keywordCollections as $kColl) {
                $kwName = null;
                if (preg_match('/(?:رصد\s+)?كلمة:\s*"?([^"]+)"?/u', $kColl->name, $matches)) {
                    $kwName = trim($matches[1]);
                } else {
                    $kwName = $kColl->name;
                }

                if (!empty($kwName)) {
                    $rawPlats = !empty($kColl->platform) ? explode(',', $kColl->platform) : ['x', 'facebook', 'instagram', 'web'];
                    $platforms = array_values(array_filter(array_map('trim', (array) $rawPlats)));

                    ScrapeKeywordsJob::dispatch(
                        $tenant->id,
                        [$kwName],
                        !empty($platforms) ? $platforms : ['x', 'facebook', 'instagram', 'web'],
                        $kColl->country ?: 'SA',
                        $kColl->id
                    );
                    $totalKeywordsDispatched++;
                }
            }

            TenantContext::forgetTenant();
        }

        $this->info("4-Hour synchronization complete. Dispatched {$totalLinksDispatched} link jobs and {$totalKeywordsDispatched} keywords.");
        return Command::SUCCESS;
    }
}
