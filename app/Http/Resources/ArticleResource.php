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
            try {
                $pubDate = \Carbon\Carbon::parse($pubDate);
            } catch (\Throwable) {
                $pubDate = null;
            }
        }
        if ($pubDate && $pubDate->year < 2000) {
            $pubDate = $this->created_at ? \Carbon\Carbon::parse($this->created_at) : null;
            if (!$pubDate || $pubDate->year < 2000) {
                $pubDate = now();
            }
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
            'published_at' => ($pubDate && $pubDate->year >= 2000) ? $pubDate->toISOString() : ($this->created_at?->toISOString() ?: now()->toISOString()),
            'date' => $pubDate ? $pubDate->format('Y-m-d') : 'الآن',
            'time' => $pubTime12,
            'formatted_date' => $formattedDate,
            'sentiment' => $this->sentiment,
            'sentiment_score' => $this->sentiment_score ? (float) $this->sentiment_score : null,
            'sentiment_reason' => $this->raw_data['sentiment_reason'] ?? null,
            'ai_metadata' => $this->ai_metadata,
            'platform' => $this->raw_data['platform'] ?? ($this->source?->type ?? 'web'),
            'reach' => $this->raw_data['reach'] ?? (!empty($this->raw_data['views']) ? (intval($this->raw_data['views']) >= 1000 ? round(intval($this->raw_data['views']) / 1000, 1) . 'K' : (string)$this->raw_data['views']) : null),
            'engagement' => $this->raw_data['engagement'] ?? (!empty($this->raw_data['likes']) ? (string)$this->raw_data['likes'] : null),
            'views' => $this->raw_data['views'] ?? null,
            'likes' => $this->raw_data['likes'] ?? null,
            'retweets' => $this->raw_data['retweets'] ?? null,
            'replies' => $this->raw_data['replies'] ?? null,
            'source' => new SourceResource($this->whenLoaded('source')),
            'keywords' => KeywordResource::collection($this->whenLoaded('keywords')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
