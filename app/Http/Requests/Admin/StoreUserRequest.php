<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->isAdmin());
    }

    /**
     * company_id is intentionally NOT accepted — the new user always joins
     * the active company context (tenancy Rule 1). Only the Super Admin may
     * create Admin users.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $actor */
        $actor = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', Rule::in($actor->isSuperAdmin() ? ['admin', 'manager'] : ['manager'])],
            'locale' => ['required', Rule::in(['es', 'en'])],
        ];
    }
}
