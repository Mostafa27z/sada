<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantComplaintSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenantId = $this->tenant_id ?? \App\Support\TenantContext::getTenantId();
        $unanalyzedCount = \App\Models\Complaint::where('tenant_id', $tenantId)->where('is_analyzed', false)->count();

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'summary' => $this->summary,
            'recommended_solutions' => $this->recommended_solutions ?? [],
            'total_complaints_analyzed' => $this->total_complaints_analyzed,
            'unanalyzed_count' => $unanalyzedCount,
            'last_complaint_id' => $this->last_complaint_id,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
