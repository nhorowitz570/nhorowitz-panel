<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Rules\UniqueEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ProfileUpdateRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    protected function prepareForValidation(): void
    {
        if (! is_string($this->input('email'))) {
            return;
        }

        /** @var User $user */
        $user = $this->user();
        $email = Str::lower(trim($this->input('email')));
        $this->merge(['email' => $email === Str::lower($user->email) ? $user->email : $email]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        $changing = $this->input('email') !== $user->email;
        $rules = $this->profileRules($user->id);
        if (! $changing) {
            $rules['email'] = array_values(array_filter($rules['email'], fn ($rule) => ! $rule instanceof UniqueEmail));
        }

        return [
            ...$rules,
            'current_password' => $changing && $user->password_set_at !== null
                ? $this->currentPasswordRules()
                : ['exclude'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your password to change your email.',
        ];
    }
}
