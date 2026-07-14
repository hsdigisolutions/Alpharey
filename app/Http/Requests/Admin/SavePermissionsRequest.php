<?php

namespace App\Http\Requests\Admin;

use App\Enums\Module;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePermissionsRequest extends FormRequest
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
        return [
            'permissions' => ['required', 'array'],
            'permissions.*.module' => ['required', Rule::enum(Module::class)],
            'permissions.*.can_view' => ['boolean'],
            'permissions.*.can_create' => ['boolean'],
            'permissions.*.can_edit' => ['boolean'],
            'permissions.*.can_delete' => ['boolean'],
            'permissions.*.can_upload' => ['boolean'],
            'permissions.*.can_download' => ['boolean'],
            'permissions.*.can_export' => ['boolean'],
            'permissions.*.can_approve' => ['boolean'],
        ];
    }
}
