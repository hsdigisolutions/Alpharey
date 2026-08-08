<?php

namespace App\Http\Requests;

use App\Rules\OwnCompanyEmployee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreFineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('vehicles.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fine_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:500'],
            'authority' => ['nullable', 'string', 'max:100'],
            'charged_to' => ['required', Rule::in(['company', 'employee'])],
            // Own-company only: a foreign employee_id could later be flagged for
            // salary deduction against another tenant's payslip.
            'employee_id' => ['nullable', 'integer', new OwnCompanyEmployee],
        ];
    }
}
