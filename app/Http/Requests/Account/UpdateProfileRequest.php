<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A user editing their OWN profile. Only the display name is editable here —
 * email is a login identifier and role/company are authorization, so those stay
 * with the admins (client decision 2026-07-23). No permission gate beyond being
 * authenticated: everyone may edit their own name.
 */
class UpdateProfileRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
