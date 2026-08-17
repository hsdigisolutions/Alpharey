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
            // A Super Admin may set ANY role for ANY user — including promoting
            // or demoting another Super Admin. (An admin can still only manage
            // Managers.) Demoting an SA to a company role requires a company
            // below, so the demoted account is never left in company-less limbo.
            'role' => ['required', Rule::in(
                $actor->isSuperAdmin()
                    ? ['super_admin', 'admin', 'manager']
                    : ['manager']
            )],
            // Required only when an SA gives a currently-company-less user (i.e.
            // a Super Admin being demoted) a company role — they must land in a
            // company. A normal company user keeps their existing company.
            'company_id' => [
                Rule::requiredIf(fn (): bool => $actor->isSuperAdmin()
                    && in_array($this->input('role'), ['admin', 'manager'], true)
                    && $target->company_id === null),
                'nullable', 'integer', 'exists:companies,id',
            ],
            'locale' => ['required', Rule::in(['es', 'en'])],
            'active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', Password::min(8)],
        ];
    }
}
