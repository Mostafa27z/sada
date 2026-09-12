<?php

namespace App\Http\Requests\Trend;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalyzeTrendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'topic' => ['required', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:5', 'max:500'],
            'platforms' => ['sometimes', 'array'],
            'platforms.*' => ['string', Rule::in(['facebook', 'instagram', 'tiktok', 'twitter', 'x'])],
            'sync' => ['sometimes', 'boolean'],
        ];
    }
}
