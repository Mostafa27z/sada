<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'opinion' => $this->opinion,
            'rate' => $this->rate,
            'priority' => $this->priority ?? 'neutral',
            'ai_recommendation' => $this->ai_recommendation,
            'status' => $this->status,
            'stage' => $this->stage ?? 'new',
            'assigned_to' => $this->assigned_to,
            'assigned_user' => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
                'email' => $this->assignedUser->email,
                'avatar' => $this->assignedUser->avatar,
                'role' => $this->assignedUser->currentRole()?->name ?? 'ممثل خدمة العملاء والدعم الفني',
                'role_slug' => $this->assignedUser->currentRole()?->slug ?? 'customer_support',
            ] : null,
            'first_responded_at' => $this->first_responded_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'is_analyzed' => (bool) $this->is_analyzed,
            'analyzed_at' => $this->analyzed_at?->toISOString(),
            'activities' => ComplaintActivityResource::collection($this->whenLoaded('activities')),
            'notes_count' => $this->relationLoaded('activities') ? $this->activities->whereIn('type', ['note', 'call_log'])->count() : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
