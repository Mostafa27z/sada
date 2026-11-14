<?php

namespace App\Jobs;

use App\Models\Collection;
use App\Models\Tenant;
use App\Services\AiScraperService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReanalyzeCollectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public int $collectionId,
        public ?array $rubric = null
    ) {}

    public function handle(AiScraperService $aiScraper): void
    {
        $collection = Collection::withoutGlobalScopes()->with('tenant')->find($this->collectionId);
        if (!$collection) {
            return;
        }

        if ($collection->tenant) {
            TenantContext::setTenant($collection->tenant);
        }

        $rubric = $this->rubric ?? $collection->getEffectiveRubric() ?? [];
        $tenant = $collection->tenant;
        if (empty($rubric['preferred_side']) && !empty($tenant?->name)) {
            $rubric['preferred_side'] = $tenant->name;
        }
        if (empty($rubric['topic_context']) && !empty($tenant?->name)) {
            $rubric['topic_context'] = $tenant->name;
        }

        try {
            $aiScraper->reanalyzeCollectionData($collection, $rubric);
            $collection->update([
                'status' => Collection::STATUS_COMPLETED,
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error("ReanalyzeCollectionJob failed for collection #{$this->collectionId}: " . $e->getMessage());
            $collection->update([
                'status' => Collection::STATUS_COMPLETED,
                'error_message' => 'تعذر إكمال إعادة التحليل لبعض العناصر: ' . $e->getMessage(),
            ]);
        } finally {
            TenantContext::forgetTenant();
        }
    }
}
