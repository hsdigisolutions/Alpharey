<?php

namespace App\Services\Payroll;

use App\Enums\AdvanceStatus;
use App\Enums\BillingMethod;
use App\Enums\DeploymentStatus;
use App\Enums\PayrollStatus;
use App\Enums\WageType;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Expense;
use App\Models\Measurement;
use App\Models\Payroll;
use App\Models\Scopes\CompanyScope;
use App\Support\PeriodLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Screen 12 — the monthly payroll run.
 *
 * Reads ONLY the wage snapshots frozen on attendance (Phase 4), never the
 * employee's live rate — a raise in August must not rewrite July's pay.
 *
 * Cross-company (Phase 5, Option A): a deployed worker stays on their HOME
 * company's payroll, but their attendance rows are written under the HOST
 * company_id. So attendance is gathered per EMPLOYEE without the tenant scope;
 * the home payroll then carries a note line per deployment and the host is
 * charged separately via DeploymentChargeService. Option B (host pays) is not
 * automated — see DECISIONS.md / dev-skill Rule 13.
 *
 * The breakdown mirrors REQUIREMENTS.md Screen 12 exactly:
 *
 *   Base Salary / Wage        (monthly salary, or per-meter earnings)
 *   Attendance Days           (daily wage types)
 *   Attendance Hours          (hourly wage types)
 *   Reimbursements
 *   Worker project expenses
 *   Overtime Pay
 *   ── Gross Pay
 *   − Advance Deductions  − Other Deductions  + Manual Additions
 *   ── NET PAY
 */
class PayrollService
{
    public function __construct(private readonly PeriodLock $lock) {}

    /**
     * Statuses that count as a worked/attended day.
     *
     * @var list<string>
     */
    private const WORKED = ['present', 'late', 'early_leave'];

    /**
     * Calculate (or recalculate) the whole month for one company.
     * Existing rows are refreshed; rows already marked paid are left alone.
     *
     * @return int number of payroll rows written
     */
    public function calculateMonth(int $companyId, string $month): int
    {
        $this->lock->assertOpen($companyId, $month.'-01', 'month');

        // Only the TENANT scope is dropped — a bare withoutGlobalScopes()
        // strips SoftDeletes too, and soft deletion does not flip `active`,
        // so a deleted monthly-salaried worker (whose pay needs no attendance
        // rows) would keep receiving a full payslip every month.
        $employees = Employee::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('active', true)
            ->get();

        $written = 0;

        DB::transaction(function () use ($employees, $companyId, $month, &$written): void {
            foreach ($employees as $employee) {
                $existing = Payroll::query()->withoutGlobalScopes()
                    ->where('employee_id', $employee->id)
                    ->where('month', $month)
                    ->first();

                // Never silently rewrite money that has already been paid out.
                if ($existing?->status === PayrollStatus::Paid) {
                    continue;
                }

                $this->calculateFor($employee, $companyId, $month, $existing);
                $written++;
            }
        });

        return $written;
    }

    /**
     * Compute one employee's month. Manual adjustments already on the row
     * (other_deductions / manual_additions / notes) are preserved across a
     * recalculation — they are the payroll clerk's input, not derived data.
     */
    public function calculateFor(Employee $employee, int $companyId, string $month, ?Payroll $existing = null): Payroll
    {
        $records = $this->attendanceFor($employee->id, $month);

        $days = $records->filter(fn (Attendance $r) => in_array($r->status->value, self::WORKED, true))->count();
        $hours = round((float) $records->sum(fn (Attendance $r) => (float) $r->hours_worked), 2);
        $otHours = round((float) $records->sum(fn (Attendance $r) => (float) $r->overtime_hours), 2);

        // Split each day's frozen total into its base and overtime portions
        // using the same formula AttendanceService used to build it.
        $baseEarned = 0.0;
        $overtimePay = 0.0;

        foreach ($records as $record) {
            $hourly = (float) ($record->hourly_rate_snapshot ?? 0);
            $base = (float) $record->hours_worked * $hourly;
            $total = (float) $record->total_amount;

            $baseEarned += $base;
            $overtimePay += max(0, $total - $base);
        }

        $wageType = $employee->wage_type;

        // "Base Salary / Wage" line: a monthly salary, or per-meter earnings.
        $baseSalary = match ($wageType) {
            WageType::Monthly => (float) ($employee->getAttribute('base_salary') ?? 0),
            WageType::PerMeter => $this->perMeterEarnings($employee, $month),
            default => 0.0,
        };

        $daysAmount = $wageType === WageType::Daily ? $baseEarned : 0.0;
        $hoursAmount = $wageType === WageType::Hourly ? $baseEarned : 0.0;

        $reimbursements = $this->reimbursementsFor($employee->id, $month);
        $projectExpenses = $this->projectExpensesFor($employee->id, $month);

        $gross = $baseSalary + $daysAmount + $hoursAmount + $overtimePay
            + $reimbursements + $projectExpenses;

        $advances = $this->advanceDeductionsFor($employee->id, $month);

        // Clerk-entered adjustments survive a recalculation.
        $otherDeductions = (float) ($existing?->getAttribute('other_deductions') ?? 0);
        $manualAdditions = (float) ($existing?->getAttribute('manual_additions') ?? 0);

        $net = $gross - $advances - $otherDeductions + $manualAdditions;

        $payroll = $existing ?? new Payroll(['employee_id' => $employee->id, 'month' => $month]);
        $payroll->company_id = $companyId;
        $payroll->employee_id = $employee->id;
        $payroll->month = $month;

        $payroll->attendance_days = (string) $days;
        $payroll->attendance_hours = (string) $hours;
        $payroll->overtime_hours = (string) $otHours;
        $payroll->wage_type = $wageType;
        $payroll->wage_rate = (string) ($employee->getAttribute('wage_rate') ?? 0);

        $payroll->base_salary = (string) round($baseSalary, 2);
        $payroll->days_amount = (string) round($daysAmount, 2);
        $payroll->hours_amount = (string) round($hoursAmount, 2);
        $payroll->overtime_pay = (string) round($overtimePay, 2);
        $payroll->reimbursements = (string) round($reimbursements, 2);
        $payroll->project_expenses = (string) round($projectExpenses, 2);
        $payroll->gross_pay = (string) round($gross, 2);
        $payroll->advance_deductions = (string) round($advances, 2);
        $payroll->other_deductions = (string) round($otherDeductions, 2);
        $payroll->manual_additions = (string) round($manualAdditions, 2);
        $payroll->net_amount = (string) round($net, 2);

        $payroll->payment_method = $employee->payment_method;
        $payroll->deployment_notes = $this->deploymentNotes($employee->id, $month);
        $payroll->status ??= PayrollStatus::Pending;
        $payroll->save();

        return $payroll;
    }

    /**
     * Every attendance row for this employee in the month, regardless of which
     * company it was logged under — a deployed worker's host days are still
     * paid by their home company under Option A.
     *
     * @return Collection<int, Attendance>
     */
    private function attendanceFor(int $employeeId, string $month): Collection
    {
        [$start, $end] = $this->bounds($month);

        return Attendance::query()->withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$start, $end])
            ->get();
    }

    /**
     * Per-meter workers are paid on APPROVED measurements only — an
     * unapproved measurement is not yet earned (Phase 4 approval pipeline).
     */
    private function perMeterEarnings(Employee $employee, string $month): float
    {
        [$start, $end] = $this->bounds($month);

        $quantity = (float) Measurement::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('approved', true)
            ->whereBetween('date', [$start, $end])
            ->sum('quantity');

        return round($quantity * (float) ($employee->getAttribute('per_meter_rate') ?? 0), 2);
    }

    /**
     * Worker project expenses: tagged to BOTH an employee and a project, they
     * are paid back through that month's payroll (REQUIREMENTS.md Screen 12).
     */
    private function projectExpensesFor(int $employeeId, string $month): float
    {
        [$start, $end] = $this->bounds($month);

        // Approved claims only — the same rule as per-meter measurements: an
        // unvetted claim is not yet money. The approve gate exists so someone
        // checks the receipt BEFORE the payroll run pays it back.
        return round((float) Expense::query()->withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->whereNotNull('project_id')
            ->where('approved', true)
            ->whereBetween('date', [$start, $end])
            ->sum('total'), 2);
    }

    /**
     * Other reimbursable costs the worker fronted — kept disjoint from the
     * project-expense line above so nothing is counted twice.
     */
    private function reimbursementsFor(int $employeeId, string $month): float
    {
        [$start, $end] = $this->bounds($month);

        return round((float) Expense::query()->withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->whereNull('project_id')
            ->where('is_reimbursable', true)
            ->where('approved', true)
            ->whereBetween('date', [$start, $end])
            ->sum('total'), 2);
    }

    /**
     * Approved advances earmarked for this payroll month.
     */
    private function advanceDeductionsFor(int $employeeId, string $month): float
    {
        $advances = Advance::query()->withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('payroll_month', $month)
            ->whereIn('status', [AdvanceStatus::Approved->value, AdvanceStatus::Deducted->value])
            ->get();

        return round((float) $advances->sum(fn (Advance $a) => (float) $a->getAttribute('amount')), 2);
    }

    /**
     * Option A note lines for the home payroll:
     * "Deployed to {host} — cost transferred".
     *
     * @return array<int, string>|null
     */
    private function deploymentNotes(int $employeeId, string $month): ?array
    {
        [$start, $end] = $this->bounds($month);

        $deployments = EmployeeDeployment::query()
            ->where('employee_id', $employeeId)
            ->where('billing_method', BillingMethod::OptionA->value)
            ->whereIn('status', [DeploymentStatus::Active->value, DeploymentStatus::Completed->value])
            ->where('deployment_start', '<=', $end)
            ->where(fn ($q) => $q->whereNull('deployment_end')->orWhere('deployment_end', '>=', $start))
            ->with('hostCompany:id,name')
            ->get();

        if ($deployments->isEmpty()) {
            return null;
        }

        return $deployments
            ->map(fn (EmployeeDeployment $d): string => __('ui.payroll.deployed_note', [
                'company' => $d->hostCompany->name,
            ]))
            ->values()->all();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function bounds(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }
}
