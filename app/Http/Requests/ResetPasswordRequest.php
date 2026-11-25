<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normalizeEmail($this->input('email'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => __('storefront.auth.fields.email'),
            'password' => __('storefront.auth.fields.password'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.max' => __('storefront.auth.errors.max', ['attribute' => __('storefront.auth.fields.email')]),
            'email.email' => __('storefront.auth.errors.email'),
            'password.confirmed' => __('storefront.auth.errors.password_confirmed'),
            'password.min' => __('storefront.auth.errors.password_min'),
        ];
    }
}
