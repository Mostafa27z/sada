<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'tenant_location_id'        => $this->tenant_location_id,
            'google_review_id'          => $this->google_review_id,
            'author_name'               => $this->author_name,
            'author_photo_url'          => $this->author_photo_url,
            'rating'                    => (int) $this->rating,
            'sentiment'                 => $this->sentiment ?? ($this->rating >= 4 ? 'positive' : ($this->rating === 3 ? 'neutral' : 'negative')),
            'sentiment_score'           => $this->sentiment_score !== null ? (float) $this->sentiment_score : null,
            'ai_analysis'               => $this->ai_analysis,
            'text'                      => $this->text,
            'published_at'              => $this->published_at?->toISOString(),
            'relative_time_description' => $this->relative_time_description,
            'likes_count'               => (int) $this->likes_count,
            'response'                  => [
                'text'         => $this->response_text,
                'published_at' => $this->response_published_at?->toISOString(),
                'is_responded' => (bool) $this->is_responded,
            ],
            'source'                    => $this->source,
            'created_at'                => $this->created_at?->toISOString(),
        ];
    }
}
