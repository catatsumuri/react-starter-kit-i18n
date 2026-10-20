<?php

namespace App\Http\Requests\Settings;

use App\Actions\LogSecurityEvent;
use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * Record a rejected password before the validation error is returned.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('password')) {
            app(LogSecurityEvent::class)->failure('account.delete_failed', $this->user());
        }

        parent::failedValidation($validator);
    }
}
