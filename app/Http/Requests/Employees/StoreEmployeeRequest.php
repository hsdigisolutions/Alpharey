<?php

namespace App\Http\Requests\Employees;

use App\Enums\PaymentMethod;
use App\Enums\WageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('employees.create');
    }

    /**
     * company_id is never accepted (tenancy Rule 1); the employee code is
     * generated server-side. Dedicated NIF/IBAN checksum rules arrive with
     * the validation-rules pass; format checks apply meanwhile.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'nif' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:100'],
            'designation' => ['nullable', 'string', 'max:100'],
            'team_leader_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'joining_date' => ['nullable', 'date'],
            'leaving_date' => ['nullable', 'date', 'after_or_equal:joining_date'],
            'active' => ['boolean'],
            'is_contracted' => ['boolean'],
            'default_check_in' => ['nullable', 'date_format:H:i'],
            'default_check_out' => ['nullable', 'date_format:H:i'],
            'wage_type' => ['nullable', Rule::enum(WageType::class)],
            'wage_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'base_salary' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'daily_wage' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'per_meter_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'overtime_policy_id' => ['nullable', 'integer', Rule::exists('overtime_policies', 'id')],
            'supervisor_overtime_policy_id' => ['nullable', 'integer', Rule::exists('overtime_policies', 'id')],
            'iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9A-Z\s]{12,32}$/i'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'has_driving_license' => ['boolean'],
            'has_company_vehicle' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
