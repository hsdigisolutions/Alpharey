<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-authentication before a sensitive self-service 2FA action (reconfigure the
 * authenticator, regenerate recovery codes). The `current_password` rule checks
 * the value against the signed-in user's hash, so someone who walks up to an
 * unlocked session cannot silently rebuild the second factor.
 */
class ConfirmPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }
}
