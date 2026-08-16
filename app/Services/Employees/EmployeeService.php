<?php

namespace App\Services\Employees;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSalaryHistory;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Create/update pipeline: code generation, salary-change history
 * (encrypted, append-only), and the effective-dated wage-rate record that
 * AttendanceService freezes onto each worked day. The wage-rate history is
 * owned by WageRateService (the single-open invariant lives there) — this
 * service only seeds the first rate on create and keeps the CURRENT rate in
 * step when the employee form edits wage fields directly. A new DATED period
 * is created only through the "Nueva Tarifa" flow, never here.
 */
class EmployeeService
{
    public function __construct(private readonly WageRateService $wageRates) {}

    private const WAGE_FIELDS = [
        'wage_type', 'wage_rate', 'base_salary', 'daily_wage', 'per_meter_rate', 'commission_percent',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data): Employee {
            $companyId = app(CurrentCompany::class)->id();

            $employee = new Employee($data);
            $employee->employee_code = Employee::nextCode((int) $companyId);
            $this->syncDepartmentName($employee);
            $employee->save();

            $this->wageRates->seedFromEmployee($employee);

            return $employee;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data): Employee {
            $employee->fill($data);
            $this->syncDepartmentName($employee);

            $changedWageFields = array_values(array_filter(
                self::WAGE_FIELDS,
                fn (string $field): bool => $employee->isDirty($field),
            ));

            foreach ($changedWageFields as $field) {
                $history = new EmployeeSalaryHistory([
                    'field' => $field,
                    'old_value' => $this->stringable($employee->getOriginal($field)),
                    'new_value' => $this->stringable($employee->getAttribute($field)),
                ]);
                $history->employee_id = $employee->id;
                $history->company_id = $employee->company_id;
                $history->changed_by = Auth::id();
                $history->created_at = now();
                $history->save();
            }

            $employee->save();

            // A direct wage-field edit is a correction to the CURRENT rate —
            // update the open record in place rather than opening a new dated
            // period (that is what "Nueva Tarifa" is for).
            if ($changedWageFields !== []) {
                $this->wageRates->syncOpenRateFromEmployee($employee);
            }

            return $employee;
        });
    }

    /**
     * Keep the legacy `department` display string in step with department_id
     * (source of truth). Runs only when department_id changed — resolves the
     * catalogue name (company-scoped), or clears the string when unset.
     */
    private function syncDepartmentName(Employee $employee): void
    {
        if (! $employee->isDirty('department_id')) {
            return;
        }

        $employee->department = $employee->department_id !== null
            ? Department::query()->whereKey($employee->department_id)->value('name')
            : null;
    }

    private function stringable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
