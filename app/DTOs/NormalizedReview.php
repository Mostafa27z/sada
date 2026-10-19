<?php

namespace App\DTOs;

class NormalizedReview
{
    public function __construct(
        public string $googleReviewId,
        public string $authorName,
        public ?string $authorPhotoUrl,
        public int $rating,
        public ?string $text,
        public string $publishedAt,
        public ?string $relativeTimeDescription = null,
        public ?string $responseText = null,
        public ?string $responsePublishedAt = null,
        public int $likesCount = 0,
        public string $source = 'apify'
    ) {}

    public function toArray(): array
    {
        return [
            'google_review_id'          => $this->googleReviewId,
            'author_name'               => $this->authorName,
            'author_photo_url'          => $this->authorPhotoUrl,
            'rating'                    => $this->rating,
            'text'                      => $this->text,
            'published_at'              => $this->publishedAt,
            'relative_time_description' => $this->relativeTimeDescription,
            'response_text'             => $this->responseText,
            'response_published_at'     => $this->responsePublishedAt,
            'is_responded'              => !empty($this->responseText),
            'likes_count'               => $this->likesCount,
            'source'                    => $this->source,
        ];
    }
}
