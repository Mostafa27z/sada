<?php

namespace App\Jobs;

use App\Models\Complaint;
use App\Models\Tenant;
use App\Services\ComplaintAiService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessComplaintAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public Complaint $complaint)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(ComplaintAiService $aiService): void
    {
        $tenant = Tenant::find($this->complaint->tenant_id);

        TenantContext::withoutTenancy(function () use ($aiService, $tenant) {
            // 1. Analyze single complaint
            $analysis = $aiService->analyzeSingleComplaint($this->complaint);
            $this->complaint->update([
                'priority' => $analysis['priority'],
                'ai_recommendation' => $analysis['ai_recommendation'],
            ]);

            // 2. Incrementally update tenant AI summary
            if ($tenant) {
                $aiService->updateTenantSummary($tenant, $this->complaint);
            }
        });
    }
}
