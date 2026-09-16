<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare inputs for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'password_confirmation' => $this->input('password_confirmation') ?? $this->input('confirmPassword'),
            'token' => $this->input('token') ?? $this->input('code'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => [
                'required',
                'string',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
                'confirmed',
            ],
        ];
    }

    /**
     * Attribute names for friendly Arabic validation.
     */
    public function attributes(): array
    {
        return [
            'email' => 'البريد الإلكتروني',
            'token' => 'رمز التحقق',
            'password' => 'كلمة المرور الجديدة',
            'password_confirmation' => 'تأكيد كلمة المرور',
        ];
    }
}
