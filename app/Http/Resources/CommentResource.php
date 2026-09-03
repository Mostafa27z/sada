<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'article_id' => $this->article_id,
            'platform' => $this->platform,
            'author' => $this->author,
            'comment_text' => $this->comment_text,
            'sentiment' => $this->sentiment,
            'sentiment_score' => $this->sentiment_score,
            'raw_data' => $this->raw_data,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
