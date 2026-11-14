<?php

namespace App\Console\Commands;

use App\Models\Collection;
use App\Models\Tenant;
use App\Services\AiScraperService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class ReanalyzeAllSentimentCommand extends Command
{
    protected $signature = 'sada:reanalyze-all
                            {--tenant= : Optional Tenant ID to limit re-analysis}
                            {--collection= : Optional Collection/Campaign ID to re-analyze only one campaign}';

    protected $description = 'Re-analyze existing stored campaigns, posts, and comments using the updated entity-perspective sentiment rules without re-scraping.';

    public function handle(AiScraperService $aiScraper): int
    {
        $collectionId = $this->option('collection');
        $tenantId = $this->option('tenant');

        if ($collectionId) {
            $collection = Collection::withoutGlobalScopes()->with('tenant')->find((int) $collectionId);
            if (!$collection) {
                $this->error("Collection #{$collectionId} not found.");
                return self::FAILURE;
            }
            if ($collection->tenant) {
                TenantContext::setTenant($collection->tenant);
            }
            $this->info("Re-analyzing collection #{$collection->id}: {$collection->name}...");
            $aiScraper->reanalyzeCollectionData($collection);
            TenantContext::forgetTenant();
            $this->info("Done.");
            return self::SUCCESS;
        }

        $tenantsQuery = Tenant::query();
        if ($tenantId) {
            $tenantsQuery->where('id', (int) $tenantId);
        }

        $tenants = $tenantsQuery->get();
        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants found.');
            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            TenantContext::setTenant($tenant);
            $this->info("Processing tenant #{$tenant->id}: {$tenant->name}");
            try {
                $aiScraper->reanalyzeTenantData($tenant);
                $this->line("  ✔ Re-analyzed collections & refreshed recent reports for {$tenant->name}");
            } catch (\Throwable $e) {
                $this->error("  ✘ Error for {$tenant->name}: " . $e->getMessage());
            }
            TenantContext::forgetTenant();
        }

        $this->info('All stored campaigns & comments have been re-analyzed successfully.');
        return self::SUCCESS;
    }
}

