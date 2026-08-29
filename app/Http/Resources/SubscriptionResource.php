<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'provider' => $this->provider,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'trial_ends_at' => $this->trial_ends_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'plan' => new PlanResource($this->whenLoaded('plan')),
        ];
    }
}
