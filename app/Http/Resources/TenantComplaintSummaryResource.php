<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantComplaintSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'summary' => $this->summary,
            'recommended_solutions' => $this->recommended_solutions ?? [],
            'total_complaints_analyzed' => $this->total_complaints_analyzed,
            'last_complaint_id' => $this->last_complaint_id,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
