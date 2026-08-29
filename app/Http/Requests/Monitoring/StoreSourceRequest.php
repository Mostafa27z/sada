<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['rss', 'website', 'news', 'blog', 'social', 'tv', 'other'])],
            'url' => ['required', 'url', 'max:500'],
            'country' => ['sometimes', 'string', 'max:10'],
            'language' => ['sometimes', 'string', 'max:10'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
