<?php

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

class IngestArticleWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'event_type' => ['sometimes', 'string'],
            'provider' => ['sometimes', 'string'],
            'article' => ['required', 'array'],
            'article.title' => ['required', 'string', 'max:500'],
            'article.url' => ['required', 'url', 'max:1000'],
            'article.content' => ['sometimes', 'nullable', 'string'],
            'article.summary' => ['sometimes', 'nullable', 'string'],
            'article.sentiment' => ['sometimes', 'nullable', 'string'],
            'article.sentiment_score' => ['sometimes', 'nullable', 'numeric'],
            'article.language' => ['sometimes', 'string', 'max:10'],
            'article.country' => ['sometimes', 'string', 'max:10'],
            'article.ai_metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
