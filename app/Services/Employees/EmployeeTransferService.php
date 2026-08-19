<?php

namespace App\Services\Employees;

use App\Enums\EquipmentIssueStatus;
use App\Enums\PayrollStatus;
use App\Models\Employee;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EmployeeWageRate;
use App\Models\Payroll;
use App\Models\Scopes\CompanyScope;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move an employee from one company to another.
 *
 * What moves: the employee profile (company_id) and their wage history (so
 * future days price under the new company). What STAYS with the old company:
 * attendance and payroll rows (they carry their own company_id — the history
 * the old company keeps). Documents stay attached to the old company and the
 * employee is flagged so the new company re-uploads them.
 *
 * Blocked when: outstanding equipment issues exist, or the current month's
 * payroll for this employee is already PAID.
 */
class EmployeeTransferService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function transfer(Employee $employee, int $toCompanyId, string $date): Employee
    {
        $fromCompanyId = $employee->company_id;

        if ($toCompanyId === $fromCompanyId) {
            throw ValidationException::withMessages(['to_company_id' => __('ui.employees.transfer_same_company')]);
        }

        // Guard 1 — outstanding equipment (anything not fully returned).
        $hasOutstanding = EmployeeEquipmentIssue::query()->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->where('status', '!=', EquipmentIssueStatus::Returned->value)
            ->exists();
        if ($hasOutstanding) {
            throw ValidationException::withMessages(['transfer' => __('ui.employees.transfer_equipment_blocked')]);
        }

        // Guard 2 — a paid payroll in the CURRENT month locks the transfer.
        $paidThisMonth = Payroll::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('month', Carbon::parse($date)->format('Y-m'))
            ->where('status', PayrollStatus::Paid->value)
            ->exists();
        if ($paidThisMonth) {
            throw ValidationException::withMessages(['transfer' => __('ui.employees.transfer_paid_blocked')]);
        }

        return DB::transaction(function () use ($employee, $fromCompanyId, $toCompanyId): Employee {
            // Wage history follows the worker.
            EmployeeWageRate::query()->withoutGlobalScope(CompanyScope::class)
                ->where('employee_id', $employee->id)
                ->update(['company_id' => $toCompanyId]);

            $employee->previous_company_id = $fromCompanyId;
            $employee->company_id = $toCompanyId; // not fillable — set directly
            $employee->transferred_at = now();
            $employee->documents_pending_reupload = true;
            $employee->save();

            $this->audit->log(
                'transferred',
                $employee,
                ['company_id' => $fromCompanyId],
                ['company_id' => $toCompanyId],
                "Transferred from company {$fromCompanyId} to {$toCompanyId}",
                'employees',
            );

            return $employee;
        });
    }
}
