<?php

namespace App\Http\Requests\Subcontractors;

use App\Enums\ExpenseResponsibility;
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
            // The deal: what the client pays us / the thaekedar's fixed budget.
            // Our profit is derived server-side, never posted.
            'client_amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'agreed_budget' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'expense_responsibility' => ['required', Rule::enum(ExpenseResponsibility::class)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::enum(SubcontractorStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
