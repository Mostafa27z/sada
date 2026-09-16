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

    protected function prepareForValidation(): void
    {
        if ($this->has('title') && !$this->has('name')) {
            $this->merge(['name' => $this->input('title')]);
        }

        $fmt = strtolower((string) ($this->input('format') ?: ''));
        $typ = strtolower((string) ($this->input('type') ?: ''));

        if ($fmt === 'xlsx') {
            $this->merge(['format' => 'excel']);
        } elseif (empty($fmt) && in_array($typ, ['pdf', 'csv', 'excel', 'xlsx', 'json'])) {
            $this->merge([
                'format' => $typ === 'xlsx' ? 'excel' : $typ,
                'type' => 'custom',
            ]);
        }

        if (!$this->has('format')) {
            $this->merge(['format' => 'excel']);
        }

        if (!$this->has('type')) {
            $this->merge(['type' => 'custom']);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'format' => ['sometimes', 'nullable', 'string', 'max:50'],
            'parameters' => ['sometimes', 'nullable'],
        ];
    }
}
