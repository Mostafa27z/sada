<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'billing_interval' => $this->billing_interval,
            'limits' => [
                'max_users' => $this->max_users,
                'max_keywords' => $this->max_keywords,
                'max_sources' => $this->max_sources,
                'max_articles' => $this->max_articles,
                'max_api_requests' => $this->max_api_requests,
            ],
            'features' => $this->features,
            'is_active' => $this->is_active,
        ];
    }
}
