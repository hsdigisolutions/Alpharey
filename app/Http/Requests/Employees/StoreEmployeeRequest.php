<?php

namespace App\Http\Requests\Employees;

use App\Enums\PaymentMethod;
use App\Enums\WageType;
use App\Support\CurrentCompany;
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
            // Department from the company catalogue (Settings). Scoped to the
            // acting company so a foreign department id is rejected.
            'department_id' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id')->where(
                    fn ($q) => $q->where('company_id', app(CurrentCompany::class)->id()),
                ),
            ],
            'designation' => ['nullable', 'string', 'max:100'],
            // The trade type (Feature 1); drives project designation rates.
            'designation_id' => ['nullable', 'integer', 'exists:designations,id'],
            'team_leader_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'joining_date' => ['nullable', 'date'],
            'leaving_date' => ['nullable', 'date', 'after_or_equal:joining_date'],
            'active' => ['boolean'],
            'is_contracted' => ['boolean'],
            'default_check_in' => ['nullable', 'date_format:H:i'],
            'default_check_out' => ['nullable', 'date_format:H:i'],
            'wage_type' => ['nullable', Rule::enum(WageType::class)],
            // A filled rate must be a REAL rate — 0 is rejected (spec acceptance
            // test 11): a 0 silently skips wage-history seeding and prices every
            // day at nothing. Leave the field empty instead.
            'wage_rate' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'base_salary' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'daily_wage' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'per_meter_rate' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'overtime_policy_id' => ['nullable', 'integer', Rule::exists('overtime_policies', 'id')],
            'supervisor_overtime_policy_id' => ['nullable', 'integer', Rule::exists('overtime_policies', 'id')],
            'iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9A-Z\s]{12,32}$/i'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'has_driving_license' => ['boolean'],
            'has_company_vehicle' => ['boolean'],
            // Grants this worker the PWA vehicle module (take/return company
            // vehicles). Distinct from has_company_vehicle (an HR data flag).
            'can_use_vehicles' => ['boolean'],
            // Works at height → the height-only required PPE (arnés) applies in
            // the compliance check.
            'works_at_height' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
