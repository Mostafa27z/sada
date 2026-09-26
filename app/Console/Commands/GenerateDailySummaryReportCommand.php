<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ReportGeneratorService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class GenerateDailySummaryReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'reports:daily-summary {--tenant= : Generate for specific tenant ID}';

    /**
     * The console command description.
     */
    protected $description = 'Generate daily AI executive summary PDF report for all active tenants';

    /**
     * Execute the console command.
     */
    public function handle(ReportGeneratorService $reportGenerator): int
    {
        $tenantId = $this->option('tenant');

        $query = Tenant::where('status', Tenant::STATUS_ACTIVE);
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        $tenants = TenantContext::withoutTenancy(fn () => $query->get());

        if ($tenants->isEmpty()) {
            $this->info('No active tenants found.');
            return self::SUCCESS;
        }

        $this->info("Generating daily AI summary PDF reports for {$tenants->count()} tenants...");

        foreach ($tenants as $tenant) {
            $this->line("Processing tenant: {$tenant->name} (ID: {$tenant->id})...");
            try {
                $report = $reportGenerator->generateDailySummary($tenant);
                if ($report) {
                    $this->info("✓ Report generated: #{$report->id} - {$report->name}");
                } else {
                    $this->warn("⚠ Skipped tenant {$tenant->name}");
                }
            } catch (\Throwable $e) {
                $this->error("✗ Failed for tenant {$tenant->name}: " . $e->getMessage());
            }
        }

        $this->info('Daily summary PDF reports process completed.');
        return self::SUCCESS;
    }
}
