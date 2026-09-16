<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Trend;
use App\Services\TrendService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeTrendsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;

    public function __construct(
        public int $trendId,
        public int $tenantId,
        public string $topic,
        public int $limit = 50,
        public array $platforms = ['facebook', 'instagram', 'tiktok', 'twitter'],
        public ?string $country = 'SA'
    ) {}

    public function handle(TrendService $trendService): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (!$tenant) {
            Log::error("AnalyzeTrendsJob aborted: Tenant #{$this->tenantId} not found");
            return;
        }

        TenantContext::setTenant($tenant);

        $trend = Trend::find($this->trendId);
        if (!$trend) {
            Log::error("AnalyzeTrendsJob aborted: Trend #{$this->trendId} not found");
            TenantContext::forgetTenant();
            return;
        }

        try {
            $trend->update([
                'status' => Trend::STATUS_PROCESSING,
            ]);

            $result = $trendService->runPipeline(
                topic: $this->topic,
                limit: $this->limit,
                platforms: $this->platforms,
                country: $this->country ?? 'SA'
            );

            $trend->update([
                'status' => Trend::STATUS_COMPLETED,
                'keywords' => $result['keywords'] ?? [],
                'hashtags' => $result['hashtags'] ?? [],
                'raw_data_count' => $result['raw_data_count'] ?? 0,
                'trends_count' => $result['trends_count'] ?? 0,
                'trends_analysis' => $result['trends_analysis'] ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::error("AnalyzeTrendsJob failed for Trend #{$this->trendId}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $trend->update([
                'status' => Trend::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        } finally {
            TenantContext::forgetTenant();
        }
    }
}
