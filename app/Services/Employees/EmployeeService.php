<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\EmployeeSalaryHistory;
use App\Models\EmployeeWageRate;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Create/update pipeline: code generation, salary-change history
 * (encrypted, append-only), and the effective-dated wage-rate record
 * consumed by attendance/payroll in later phases.
 */
class EmployeeService
{
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
            $employee->save();

            $this->recordWageRate($employee);

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

            if ($changedWageFields !== []) {
                $this->recordWageRate($employee);
            }

            return $employee;
        });
    }

    private function recordWageRate(Employee $employee): void
    {
        if ($employee->wage_type === null || $employee->getAttribute('wage_rate') === null) {
            return;
        }

        EmployeeWageRate::query()
            ->where('employee_id', $employee->id)
            ->update(['is_default' => false]);

        $rate = new EmployeeWageRate([
            'wage_type' => $employee->wage_type->value,
            'rate' => (string) $employee->getAttribute('wage_rate'),
            'effective_from' => now()->toDateString(),
            'is_default' => true,
        ]);
        $rate->employee_id = $employee->id;
        $rate->company_id = $employee->company_id;
        $rate->save();
    }

    private function stringable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
