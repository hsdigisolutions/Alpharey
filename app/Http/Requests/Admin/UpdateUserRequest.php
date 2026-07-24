<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->isAdmin());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $actor */
        $actor = $this->user();

        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            // A Super Admin's role is LOCKED: nobody — not even another SA —
            // demotes one through this form (a demoted SA would be left with
            // no company: limbo). Deactivate the account instead. Editing an
            // SA therefore only ever keeps role = super_admin, which still
            // lets another SA fix their name/email/password.
            'role' => ['required', Rule::in(
                $actor->isSuperAdmin()
                    ? ($target->isSuperAdmin() ? ['super_admin'] : ['admin', 'manager'])
                    : ['manager']
            )],
            'locale' => ['required', Rule::in(['es', 'en'])],
            'active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', Password::min(8)],
        ];
    }
}
