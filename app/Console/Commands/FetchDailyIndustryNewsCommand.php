<?php

namespace App\Console\Commands;

use App\Jobs\FetchTenantIndustryNewsJob;
use App\Models\Tenant;
use App\Services\IndustryNewsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FetchDailyIndustryNewsCommand extends Command
{
    protected $signature = 'sada:fetch-industry-news 
                            {--tenant= : Run for a specific tenant ID} 
                            {--sync : Run synchronously instead of dispatching to queue}
                            {--date= : Custom batch date (YYYY-MM-DD)}';

    protected $description = 'Fetch and curate top 10 daily industry news with actionable recommendations for active tenants';

    public function handle(IndustryNewsService $newsService): int
    {
        $todayKsa = Carbon::now('Asia/Riyadh')->toDateString();
        $batchDate = $this->option('date') ?: $todayKsa;
        $isSync = (bool)$this->option('sync');

        $this->info("Starting Daily Industry News Fetcher for date: {$batchDate} (KSA Time)");

        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenants = Tenant::where('id', $tenantId)->get();
        } else {
            $tenants = Tenant::whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get();
        }

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$tenants->count()} tenant(s) to process.");

        foreach ($tenants as $tenant) {
            $this->line("Processing Tenant #{$tenant->id}: {$tenant->name}...");

            if ($isSync) {
                try {
                    $items = $newsService->fetchAndProcessDailyNewsForTenant($tenant, $batchDate);
                    $this->info(" Successfully processed and saved " . count($items) . " top news items.");
                } catch (\Throwable $e) {
                    $this->error(" Failed for tenant #{$tenant->id}: " . $e->getMessage());
                }
            } else {
                FetchTenantIndustryNewsJob::dispatch($tenant->id, $batchDate);
                $this->info(" Dispatched FetchTenantIndustryNewsJob to queue.");
            }
        }

        $this->info('Daily Industry News dispatch completed.');
        return Command::SUCCESS;
    }
}
