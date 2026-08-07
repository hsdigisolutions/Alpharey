<?php

namespace App\Http\Requests\Employees;

use App\Enums\WageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * "Nueva Tarifa" — open a new dated wage rate for an employee. A rate of zero
 * is rejected (edge case 5); the "must start after the current rate" guard
 * lives in WageRateService, which knows the employee's open record.
 */
class StoreWageRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('employees.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'wage_type' => ['required', Rule::enum(WageType::class)],
            'rate' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:500'],
            // Client confirmation that a past date reprices unpaid attendance.
            'confirm_recalculate' => ['nullable', 'boolean'],
        ];
    }
}
