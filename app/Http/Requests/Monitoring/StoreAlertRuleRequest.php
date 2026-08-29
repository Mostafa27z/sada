<?php

namespace App\Http\Requests\Monitoring;

use App\Models\AlertRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trigger_type' => ['required', Rule::in(['keyword', 'sentiment', 'volume', 'all'])],
            'keyword_id' => ['sometimes', 'nullable', 'exists:keywords,id'],
            'sentiment' => ['sometimes', 'nullable', Rule::in(['positive', 'negative', 'neutral'])],
            'channels' => ['sometimes', 'nullable', 'array'],
            'recipients' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
