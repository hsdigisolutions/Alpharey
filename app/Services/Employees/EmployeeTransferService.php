<?php

namespace App\Services\Employees;

use App\Enums\EquipmentIssueStatus;
use App\Enums\PayrollStatus;
use App\Models\Employee;
use App\Models\EmployeeCompanyHistory;
use App\Models\EmployeeEquipmentIssue;
use App\Models\Payroll;
use App\Models\Scopes\CompanyScope;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfer an employee from one company to another — SINGLE-RECORD model.
 *
 * There is exactly ONE employee record per person, always. A transfer flips
 * that record's company_id IN PLACE (it does NOT create a new record). This is
 * the redesign that replaced the earlier "new record per transfer" model, which
 * stranded attendance history on superseded records and multiplied records on
 * round-trip transfers.
 *
 * On transfer:
 *   - the record's company_id is set to the new company (same id, same login,
 *     same documents, same attendance — nothing is copied or moved);
 *   - the current company stint is closed and a new one opened in
 *     employee_company_history (the Employment History tab reads from there);
 *   - the open wage-rate period is carried into the new company from the
 *     transfer date (same amount, new company tag) — old periods keep their
 *     company, exactly like attendance;
 *   - transferred_at is stamped so absence-counting at the new company starts
 *     from the transfer date;
 *   - documents_pending_reupload flags that the new company's employment
 *     documents are still needed.
 *
 * PERMANENT RULE: every attendance row keeps the company_id it was logged under
 * forever — this service never touches the attendance table, so a worker's
 * history stays with the company where it was earned.
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

        return DB::transaction(function () use ($employee, $fromCompanyId, $toCompanyId, $date): Employee {
            $transferDate = Carbon::parse($date);
            $transferDay = $transferDate->toDateString();

            // --- Company-stint history (Employment History tab reads this) ---
            $openStint = EmployeeCompanyHistory::query()
                ->where('employee_id', $employee->id)
                ->whereNull('ended_at')
                ->orderByDesc('started_at')
                ->first();

            if ($openStint !== null && $openStint->started_at->toDateString() >= $transferDay) {
                // Same-day (or a transfer dated on/before the current stint's
                // start) — just move the still-open stint, never open a
                // zero-length one. This is what keeps round-trip transfers from
                // proliferating stint rows.
                $openStint->company_id = $toCompanyId;
                $openStint->save();
            } else {
                if ($openStint !== null) {
                    $openStint->ended_at = $transferDate;
                    $openStint->save();
                }
                EmployeeCompanyHistory::create([
                    'employee_id' => $employee->id,
                    'company_id' => $toCompanyId,
                    'started_at' => $transferDay,
                    'ended_at' => null,
                ]);
            }

            // --- Flip the ONE record's company in place ---
            // company_id is guarded (not mass-assignable); set on the instance
            // directly and persist. Login, documents and attendance are untouched.
            $employee->previous_company_id = $fromCompanyId;
            $employee->transferred_at = $transferDate;
            $employee->transferred_out_at = null; // single record is never "transferred out"
            $employee->documents_pending_reupload = true;
            $employee->company_id = $toCompanyId;
            $employee->save();

            // --- Wage: carry the same rate into the new company from today ---
            app(WageRateService::class)->openTransferStint($employee, $toCompanyId, $transferDay);

            $this->audit->log(
                'transferred',
                $employee,
                ['company_id' => $fromCompanyId],
                ['company_id' => $toCompanyId],
                "Transferred from company {$fromCompanyId} to {$toCompanyId} (single record, history + attendance kept)",
                'employees',
            );

            return $employee;
        });
    }
}
