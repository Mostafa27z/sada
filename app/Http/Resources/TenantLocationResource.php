<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantLocationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'tenant_id'           => $this->tenant_id,
            'business_name'       => $this->business_name,
            'address'             => $this->address,
            'latitude'            => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude'           => $this->longitude !== null ? (float) $this->longitude : null,
            'google_place_id'     => $this->google_place_id,
            'maps_url'            => $this->maps_url,
            'provider'            => $this->provider,
            'is_verified_owner'   => (bool) $this->is_verified_owner,
            'can_reply'           => (bool) $this->can_reply,
            'sync_status'         => $this->sync_status,
            'last_synced_at'      => $this->last_synced_at?->toISOString(),
            'last_error_message'  => $this->last_error_message,
            'ai_summary'          => $this->ai_summary,
            'ai_recommendations'  => $this->ai_recommendations,
            'ai_analyzed_at'      => $this->ai_analyzed_at?->toISOString(),
            'stats'               => [
                'total_reviews'       => (int) $this->total_reviews,
                'average_rating'      => (float) $this->average_rating,
                'rating_distribution' => $this->rating_distribution ?? [
                    '1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0,
                ],
                'unanswered_reviews'  => (int) $this->unanswered_reviews,
            ],
            'created_at'          => $this->created_at?->toISOString(),
            'updated_at'          => $this->updated_at?->toISOString(),
        ];
    }
}
