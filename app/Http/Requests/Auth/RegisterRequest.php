<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation, mapping frontend field names if provided.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'company_name' => $this->input('company_name') ?? $this->input('companyName'),
            'company_email' => $this->input('company_email') ?? $this->input('companyEmail'),
            'name' => $this->input('name') ?? $this->input('fullName'),
            'email' => $this->input('email') ?? $this->input('workEmail'),
            'password_confirmation' => $this->input('password_confirmation') ?? $this->input('confirmPassword'),
            'agree_to_terms' => $this->input('agree_to_terms') ?? $this->input('agreeToTerms'),
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
            // Company Info
            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'country' => ['required', 'string', 'max:100'],
            'industry' => ['required', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:255'],

            // Admin User Info
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
                'confirmed',
            ],
            'agree_to_terms' => ['required'],
        ];
    }

    /**
     * Custom Arabic attribute names and error messages.
     */
    public function attributes(): array
    {
        return [
            'company_name' => 'اسم الشركة',
            'company_email' => 'البريد الإلكتروني للشركة',
            'phone' => 'رقم الهاتف',
            'country' => 'اسم الدولة',
            'industry' => 'مجال الشركة',
            'website' => 'الموقع الإلكتروني',
            'name' => 'الاسم الكامل',
            'email' => 'البريد الإلكتروني للعمل',
            'password' => 'كلمة المرور',
            'password_confirmation' => 'تأكيد كلمة المرور',
            'agree_to_terms' => 'الموافقة على الشروط والأحكام',
        ];
    }
}
