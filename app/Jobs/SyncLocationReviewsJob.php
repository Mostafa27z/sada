<?php

namespace App\Jobs;

use App\Models\TenantLocation;
use App\Services\Reviews\ReviewSyncManager;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncLocationReviewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 240;
    public int $tries = 2;

    public function __construct(
        public int $locationId,
        public int $maxReviews = 50
    ) {}

    public function handle(ReviewSyncManager $syncManager): void
    {
        // Bypass global tenant scope to locate the location model reliably in worker context
        $location = TenantContext::withoutTenancy(fn () => TenantLocation::find($this->locationId));

        if (!$location) {
            Log::warning("SyncLocationReviewsJob: TenantLocation #{$this->locationId} not found.");
            return;
        }

        Log::info("SyncLocationReviewsJob: Starting review sync for Location #{$location->id} ({$location->business_name}), Tenant #{$location->tenant_id}");

        TenantContext::withoutTenancy(function () use ($location, $syncManager) {
            try {
                $result = $syncManager->sync($location, $this->maxReviews);
                Log::info("SyncLocationReviewsJob: Sync completed for Location #{$location->id}", $result);
            } catch (\Throwable $e) {
                Log::error("SyncLocationReviewsJob: Unhandled exception for Location #{$location->id}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);

                $location->update([
                    'sync_status' => 'failed',
                    'last_error_message' => $e->getMessage(),
                ]);
            }
        });
    }
}
