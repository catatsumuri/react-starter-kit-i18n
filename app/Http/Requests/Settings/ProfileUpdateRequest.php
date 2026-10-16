<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class ProfileUpdateRequest extends FormRequest
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            'current_password' => $this->isChangingEmail()
                ? ['bail', ...$this->currentPasswordRules()]
                : ['nullable'],
        ];
    }

    /**
     * Determine whether the request changes the user's email address.
     */
    protected function isChangingEmail(): bool
    {
        $email = $this->input('email');

        return ! is_string($email) || $email !== $this->user()->email;
    }

    /**
     * Get the validated data without the password confirmation field.
     *
     * @return array<string, mixed>
     */
    public function profileData(): array
    {
        return Arr::only($this->validated(), ['name', 'email']);
    }
}
