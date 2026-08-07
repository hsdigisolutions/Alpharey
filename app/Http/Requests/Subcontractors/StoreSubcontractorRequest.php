<?php

namespace App\Http\Requests\Subcontractors;

use App\Enums\SubcontractorStatus;
use App\Rules\OwnCompanyProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreSubcontractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->isMethod('post') ? 'subcontractors.create' : 'subcontractors.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'project_id' => ['nullable', 'integer', new OwnCompanyProject],
            'nif' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::enum(SubcontractorStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
