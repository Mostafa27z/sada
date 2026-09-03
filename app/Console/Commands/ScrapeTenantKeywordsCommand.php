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
        $tenants = Tenant::where('is_active', true)->get();

        if ($tenants->isEmpty()) {
            $this->info('No active tenants found.');
            return Command::SUCCESS;
        }

        $dispatchedCount = 0;

        foreach ($tenants as $tenant) {
            TenantContext::setTenant($tenant);

            $keywords = Keyword::where('status', Keyword::STATUS_ACTIVE)->pluck('name')->toArray();

            if (!empty($keywords)) {
                ScrapeKeywordsJob::dispatch($tenant->id, $keywords);
                $dispatchedCount++;
                $this->info("Dispatched keyword scraper job for tenant #{$tenant->id} with " . count($keywords) . " keywords.");
            }

            TenantContext::forgetTenant();
        }

        $this->info("Daily keyword scraping complete. Dispatched jobs for {$dispatchedCount} tenants.");
        return Command::SUCCESS;
    }
}
