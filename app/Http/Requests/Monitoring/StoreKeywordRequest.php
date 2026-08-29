<?php

namespace App\Http\Requests\Monitoring;

use App\Models\Keyword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKeywordRequest extends FormRequest
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
            'language' => ['sometimes', 'string', 'max:10'],
            'country' => ['sometimes', 'string', 'max:10'],
            'priority' => ['sometimes', Rule::in([Keyword::PRIORITY_LOW, Keyword::PRIORITY_MEDIUM, Keyword::PRIORITY_HIGH, Keyword::PRIORITY_CRITICAL])],
            'match_type' => ['sometimes', Rule::in([Keyword::MATCH_EXACT, Keyword::MATCH_PHRASE, Keyword::MATCH_CONTAINS, Keyword::MATCH_ADVANCED])],
            'configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
