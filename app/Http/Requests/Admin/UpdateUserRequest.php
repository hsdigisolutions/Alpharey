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

        return $user instanceof User && ($user->isSuperAdmin() || $user->isCompanyAdmin());
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
            // When editing a SA, the role field must at minimum allow keeping
            // them as super_admin (e.g. to change only their email or password).
            'role' => ['required', Rule::in(
                $actor->isSuperAdmin()
                    ? ($target->isSuperAdmin() ? ['super_admin', 'company_admin', 'user'] : ['company_admin', 'user'])
                    : ['user']
            )],
            'locale' => ['required', Rule::in(['es', 'en'])],
            'active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', Password::min(8)],
        ];
    }
}
