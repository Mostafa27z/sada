<?php

namespace App\Jobs;

use App\Models\Complaint;
use App\Models\Tenant;
use App\Services\ComplaintAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessComplaintAiJob implements ShouldQueue
{
    use Queueable;

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
        // 1. Analyze single complaint
        $analysis = $aiService->analyzeSingleComplaint($this->complaint);
        $this->complaint->update([
            'priority' => $analysis['priority'],
            'ai_recommendation' => $analysis['ai_recommendation'],
        ]);

        // 2. Incrementally update tenant AI summary
        $tenant = Tenant::find($this->complaint->tenant_id);
        if ($tenant) {
            $aiService->updateTenantSummary($tenant, $this->complaint);
        }
    }
}
