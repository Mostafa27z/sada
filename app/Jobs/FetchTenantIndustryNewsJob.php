<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\IndustryNewsService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchTenantIndustryNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(
        public int $tenantId,
        public ?string $targetDate = null
    ) {}

    public function handle(IndustryNewsService $newsService): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (!$tenant) {
            Log::warning("FetchTenantIndustryNewsJob: Tenant #{$this->tenantId} not found.");
            return;
        }

        if (!$tenant->isActive()) {
            Log::info("FetchTenantIndustryNewsJob: Skipping inactive tenant #{$this->tenantId}.");
            return;
        }

        Log::info("FetchTenantIndustryNewsJob: Starting daily news fetch for tenant #{$tenant->id} ({$tenant->name})");

        TenantContext::withoutTenancy(function () use ($tenant, $newsService) {
            try {
                $newsService->fetchAndProcessDailyNewsForTenant($tenant, $this->targetDate);
            } catch (\Throwable $e) {
                Log::error("FetchTenantIndustryNewsJob: Error processing tenant #{$tenant->id}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });
    }
}
