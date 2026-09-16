<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $realDate = $this->comment_created_at ?: $this->created_at;
        if ($realDate && is_string($realDate)) {
            $realDate = \Carbon\Carbon::parse($realDate);
        }

        $time12 = '';
        $formattedDate = 'الآن';
        if ($realDate) {
            $realDate = $realDate->copy()->timezone(config('app.timezone', 'Asia/Riyadh'));
            $period = $realDate->format('A') === 'PM' ? 'م' : 'ص';
            $time12 = $realDate->format('h:i') . ' ' . $period;
            $formattedDate = $realDate->format('Y-m-d') . ' • ' . $time12;
        }

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'article_id' => $this->article_id,
            'parent_id' => $this->parent_id,
            'platform' => $this->platform,
            'author' => $this->author,
            'comment_text' => $this->comment_text,
            'sentiment' => $this->sentiment,
            'sentiment_score' => $this->sentiment_score,
            'likes_count' => $this->likes_count ?? 0,
            'replies_count' => $this->replies_count ?? ($this->relationLoaded('replies') ? $this->replies->count() : 0),
            'comment_created_at' => $realDate?->toISOString(),
            'date' => $realDate ? $realDate->format('Y-m-d') : 'الآن',
            'time' => $time12,
            'formatted_date' => $formattedDate,
            'replies' => CommentResource::collection($this->whenLoaded('replies', fn() => $this->replies, collect())),
            'raw_data' => $this->raw_data,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
