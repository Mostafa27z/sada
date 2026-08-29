<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'content' => $this->content,
            'url' => $this->url,
            'image_url' => $this->image_url,
            'author' => $this->author,
            'language' => $this->language,
            'country' => $this->country,
            'category' => $this->category,
            'published_at' => $this->published_at?->toISOString(),
            'sentiment' => $this->sentiment,
            'sentiment_score' => $this->sentiment_score ? (float) $this->sentiment_score : null,
            'ai_metadata' => $this->ai_metadata,
            'source' => new SourceResource($this->whenLoaded('source')),
            'keywords' => KeywordResource::collection($this->whenLoaded('keywords')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
