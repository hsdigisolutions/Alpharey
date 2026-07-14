<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMailSettingsRequest extends FormRequest
{
    /**
     * SMTP credentials are Super Admin territory.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'from_name' => ['required', 'string', 'max:100'],
            'from_address' => ['required', 'email', 'max:255'],
        ];
    }
}
