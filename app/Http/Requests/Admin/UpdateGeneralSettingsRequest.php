<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGeneralSettingsRequest extends FormRequest
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
            'app_name' => ['required', 'string', 'max:100'],
            'default_locale' => ['required', Rule::in(['es', 'en'])],
            'timezone' => ['required', 'timezone:all'],
            'session_timeout_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ];
    }
}
