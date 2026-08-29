<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'url' => $this->url,
            'country' => $this->country,
            'language' => $this->language,
            'category' => $this->category,
            'status' => $this->status,
            'is_global' => $this->tenant_id === null,
            'last_synced_at' => $this->last_synced_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
