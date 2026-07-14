<?php

namespace App\Http\Requests\Companies;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class DestroyCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * The confirmation step: the exact company name must be typed
     * (REQUIREMENTS.md §2 — "serious action").
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'confirm_name' => ['required', 'string'],
        ];
    }
}
