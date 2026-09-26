<?php

namespace App\Http\Requests\Tenants;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->route('tenant')?->id ?? $this->route('tenant');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('tenants', 'slug')->ignore($tenantId),
            ],
            'logo' => ['sometimes', 'nullable'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
