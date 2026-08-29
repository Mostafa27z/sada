<?php

namespace App\Http\Requests\Monitoring;

use App\Models\Report;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['executive', 'sentiment', 'volume', 'custom'])],
            'format' => ['required', Rule::in(['pdf', 'csv', 'excel', 'json'])],
            'parameters' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
