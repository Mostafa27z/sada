<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trigger_type' => $this->trigger_type,
            'keyword_id' => $this->keyword_id,
            'sentiment' => $this->sentiment,
            'channels' => $this->channels,
            'recipients' => $this->recipients,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
