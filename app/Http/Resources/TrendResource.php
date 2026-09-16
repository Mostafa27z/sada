<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrendResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'topic' => $this->topic,
            'status' => $this->status,
            'limit' => $this->limit,
            'platforms' => $this->platforms,
            'country' => $this->country ?? 'SA',
            'keywords' => $this->keywords,
            'hashtags' => $this->hashtags,
            'raw_data_count' => $this->raw_data_count,
            'trends_count' => $this->trends_count,
            'trends_analysis' => $this->trends_analysis,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
