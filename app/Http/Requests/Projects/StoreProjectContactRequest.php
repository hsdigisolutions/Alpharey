<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectContactRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create/update a project's client-side contact. Gated by `projects.edit`;
 * company_id is never accepted from input (the BelongsToCompany hook fills it).
 */
class StoreProjectContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('projects.edit');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(ProjectContactRole::class)],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
