<?php

namespace App\Http\Requests\Tenants;

use Illuminate\Foundation\Http\FormRequest;

class CreateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'unique:tenants,slug'],
            'logo' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
