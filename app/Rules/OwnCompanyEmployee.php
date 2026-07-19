<?php

namespace App\Rules;

use App\Enums\DeploymentStatus;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Scopes\CompanyScope;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The employee named in the request must belong to the ACTING company.
 *
 * Rule::exists('employees', 'id') checks the raw table — any company's
 * employee id passes it. But payroll gathers advances, expenses and
 * measurements per EMPLOYEE across companies (Option A), so an id from
 * another company would inject money into — or deduct it from — that
 * company's payslips. Ownership is part of validity, not a UI concern.
 *
 * $allowDeployed widens the check for attendance: a worker deployed INTO
 * the acting company legitimately has days logged here (Phase 5).
 */
class OwnCompanyEmployee implements ValidationRule
{
    public function __construct(private readonly bool $allowDeployed = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $companyId = app(CurrentCompany::class)->id();

        if ($companyId === null) {
            $fail(__('ui.validation.no_company_context'));

            return;
        }

        // Tenant scope dropped explicitly (the session company may differ from
        // a relation's), SoftDeletes kept: a removed employee never validates.
        $employee = Employee::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->find($value);

        if ($employee === null) {
            $fail(__('validation.exists', ['attribute' => $attribute]));

            return;
        }

        if ($employee->company_id === $companyId) {
            return;
        }

        if ($this->allowDeployed && $this->isDeployedInto($employee->id, $companyId)) {
            return;
        }

        $fail(__('ui.validation.employee_not_of_company'));
    }

    private function isDeployedInto(int $employeeId, int $companyId): bool
    {
        return EmployeeDeployment::query()
            ->where('employee_id', $employeeId)
            ->where('host_company_id', $companyId)
            ->whereIn('status', [DeploymentStatus::Active->value, DeploymentStatus::Completed->value])
            ->exists();
    }
}
