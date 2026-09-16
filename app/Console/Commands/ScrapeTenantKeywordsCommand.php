<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeKeywordsJob;
use App\Models\Keyword;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class ScrapeTenantKeywordsCommand extends Command
{
    protected $signature = 'sada:scrape-keywords';
    protected $description = 'Run scheduled daily keyword scraping for active tenants';

    public function handle(): int
    {
        $tenants = Tenant::whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get();

        if ($tenants->isEmpty()) {
            $this->info('No active tenants found.');
            return Command::SUCCESS;
        }

        $dispatchedCount = 0;

        foreach ($tenants as $tenant) {
            TenantContext::setTenant($tenant);

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
                $dispatchedCount++;
                $this->info("Dispatched keyword scraper job for tenant #{$tenant->id} with " . count($keywords) . " keywords.");
            }

            TenantContext::forgetTenant();
        }

        $this->info("Daily keyword scraping complete. Dispatched jobs for {$dispatchedCount} tenants.");
        return Command::SUCCESS;
    }
}
