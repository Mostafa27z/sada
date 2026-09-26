<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;

class StoreCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'link' => ['sometimes', 'nullable', 'string', 'max:500'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:255'],
            'platforms' => ['sometimes', 'nullable', 'array'],
            'country' => ['sometimes', 'nullable', 'string', 'max:10'],
            'comments_limit' => ['sometimes', 'nullable'],
            'keyword' => ['sometimes', 'nullable', 'string'],
            'keywords' => ['sometimes', 'nullable', 'array'],
            'date_from' => ['sometimes', 'nullable', 'string', 'max:20'],
            'date_to' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }
}
