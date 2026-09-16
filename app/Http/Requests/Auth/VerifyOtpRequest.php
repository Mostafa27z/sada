<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->input('code') ?? $this->input('otp'),
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string', 'size:6'],
            'type' => ['nullable', 'string', 'in:password_reset,email_verification'],
        ];
    }

    public function attributes(): array
    {
        return [
            'email' => 'البريد الإلكتروني',
            'code' => 'رمز التحقق',
        ];
    }
}
