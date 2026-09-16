<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pubDate = $this->published_at ?: $this->created_at;
        if ($pubDate && is_string($pubDate)) {
            $pubDate = \Carbon\Carbon::parse($pubDate);
        }
        $pubTime12 = '';
        $formattedDate = 'الآن';
        if ($pubDate) {
            $pubDate = $pubDate->copy()->timezone(config('app.timezone', 'Asia/Riyadh'));
            $period = $pubDate->format('A') === 'PM' ? 'م' : 'ص';
            $pubTime12 = $pubDate->format('h:i') . ' ' . $period;
            $formattedDate = $pubDate->format('Y-m-d') . ' • ' . $pubTime12;
        }

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
            'date' => $pubDate ? $pubDate->format('Y-m-d') : 'الآن',
            'time' => $pubTime12,
            'formatted_date' => $formattedDate,
            'sentiment' => $this->sentiment,
            'sentiment_score' => $this->sentiment_score ? (float) $this->sentiment_score : null,
            'ai_metadata' => $this->ai_metadata,
            'platform' => $this->raw_data['platform'] ?? ($this->source?->type ?? 'web'),
            'reach' => $this->raw_data['reach'] ?? null,
            'engagement' => $this->raw_data['engagement'] ?? null,
            'source' => new SourceResource($this->whenLoaded('source')),
            'keywords' => KeywordResource::collection($this->whenLoaded('keywords')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
