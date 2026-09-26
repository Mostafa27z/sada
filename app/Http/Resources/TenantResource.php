<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ulid' => $this->ulid,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo' => $this->logo,
            'status' => $this->status,
            'is_owner' => $this->pivot?->is_owner ?? false,
            'settings' => $this->settings,
            'plan' => $this->plan ? [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'slug' => $this->plan->slug,
                'price' => $this->plan->price,
                'features' => $this->plan->features,
            ] : null,
            'subscription' => $this->subscription ? [
                'id' => $this->subscription->id,
                'status' => $this->subscription->status,
                'trial_ends_at' => $this->subscription->trial_ends_at?->toISOString(),
                'ends_at' => $this->subscription->ends_at?->toISOString(),
                'billing_cycle' => $this->subscription->billing_cycle ?? 'yearly',
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
