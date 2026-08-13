<?php

namespace App\Services\Payroll;

use App\Enums\AdvanceStatus;
use App\Enums\AttendanceStatus;
use App\Enums\BillingMethod;
use App\Enums\DayType;
use App\Enums\DeploymentStatus;
use App\Enums\PayrollStatus;
use App\Enums\WageType;
use App\Enums\WorkerExpenseStatus;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Expense;
use App\Models\Measurement;
use App\Models\Payroll;
use App\Models\Scopes\CompanyScope;
use App\Models\VehicleFine;
use App\Models\VehicleFuelRecord;
use App\Models\WorkerExpense;
use App\Services\Employees\WageRateService;
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
    public function __construct(
        private readonly PeriodLock $lock,
        private readonly WageRateService $wageRates,
    ) {}

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

        // Rows that carry pay: worked days AND paid leave (leave books its wage
        // snapshot so it reaches payroll — an unpaid leave row is simply 0).
        $paidRows = $records->filter(
            fn (Attendance $r) => in_array($r->status->value, self::WORKED, true)
                || $r->status === AttendanceStatus::Leave,
        );

        // Earnings by DAY TYPE, from each day's frozen total (never recomputed).
        //   full/half → daily jornadas; hourly → base + overtime; per_meter → piecework.
        $fullHalfAmount = 0.0;
        $hourlyBase = 0.0;
        $overtimePay = 0.0;
        $perMeterAmount = 0.0;

        foreach ($paidRows as $record) {
            $total = $this->rowAmount($record, $employee);

            match ($this->effectiveDayType($record)) {
                DayType::Full, DayType::Half => $fullHalfAmount += $total,
                DayType::PerMeter => $perMeterAmount += $total,
                DayType::Hourly => (function () use ($record, $total, &$hourlyBase, &$overtimePay): void {
                    $base = (float) $record->hours_worked * (float) ($record->hourly_rate_snapshot ?? 0);
                    $hourlyBase += $base;
                    $overtimePay += max(0, $total - $base);
                })(),
            };
        }

        $wageType = $employee->wage_type;

        // "Base Salary / Wage" line: a monthly salary pro-rated by presence
        // (spec C4), or per-meter earnings from approved measurements. Per-meter
        // DAYS (attendance) are shown on their own "Por metros" summary line, so
        // they stay OUT of this bucket.
        $baseSalary = match ($wageType) {
            WageType::Monthly => $this->monthlyProRata($employee, $month, $records),
            WageType::PerMeter => $this->perMeterEarnings($employee, $month),
            default => 0.0,
        };

        $daysAmount = $fullHalfAmount;
        $hoursAmount = $hourlyBase;

        // When the rate changed mid-month, break the worked days into rate periods.
        $ratePeriods = $this->ratePeriods($records, $wageType);

        // Per-day-type breakdown for the payslip (jornadas completas/medias/horas/metros).
        $attendanceEarnings = $daysAmount + $hoursAmount + $overtimePay + $perMeterAmount;
        $dayTypeSummary = $attendanceEarnings > 0 ? $this->dayTypeSummary($paidRows, $employee) : null;

        $reimbursements = $this->reimbursementsFor($employee->id, $month)
            + $this->pwaExpensesFor($employee->id, $month)
            + $this->fuelReimbursementsFor($employee->id, $month);
        $projectExpenses = $this->projectExpensesFor($employee->id, $month);

        $gross = $baseSalary + $daysAmount + $hoursAmount + $overtimePay
            + $perMeterAmount + $reimbursements + $projectExpenses;

        $advances = $this->advanceDeductionsFor($employee->id, $month);
        $fineDeductions = $this->vehicleFinesFor($employee->id, $month);
        $expenseDeductions = $this->expenseSalaryDeductionsFor($employee->id, $month);

        // Clerk-entered adjustments survive a recalculation.
        $otherDeductions = (float) ($existing?->getAttribute('other_deductions') ?? 0);
        $manualAdditions = (float) ($existing?->getAttribute('manual_additions') ?? 0);

        $net = $gross - $advances - $fineDeductions - $expenseDeductions - $otherDeductions + $manualAdditions;

        $payroll = $existing ?? new Payroll(['employee_id' => $employee->id, 'month' => $month]);
        $payroll->company_id = $companyId;
        $payroll->employee_id = $employee->id;
        $payroll->month = $month;

        $payroll->attendance_days = (string) $days;
        $payroll->attendance_hours = (string) $hours;
        $payroll->overtime_hours = (string) $otHours;
        $payroll->wage_type = $wageType;
        // The Tarifa figure: the rate matching the worker's OWN wage type.
        // Reading the hourly `wage_rate` column for every type left daily /
        // monthly / per-meter workers showing 0,00 € (their hourly column is
        // null once wage history syncs the profile).
        $payroll->wage_rate = (string) (match ($wageType) {
            WageType::Daily => $employee->getAttribute('daily_wage'),
            WageType::Monthly => $employee->getAttribute('base_salary'),
            WageType::PerMeter => $employee->getAttribute('per_meter_rate'),
            default => $employee->getAttribute('wage_rate'),
        } ?? 0);

        $payroll->base_salary = (string) round($baseSalary, 2);
        $payroll->days_amount = (string) round($daysAmount, 2);
        $payroll->hours_amount = (string) round($hoursAmount, 2);
        $payroll->rate_periods = $ratePeriods;
        $payroll->day_type_summary = $dayTypeSummary;
        $payroll->overtime_pay = (string) round($overtimePay, 2);
        $payroll->reimbursements = (string) round($reimbursements, 2);
        $payroll->project_expenses = (string) round($projectExpenses, 2);
        $payroll->gross_pay = (string) round($gross, 2);
        $payroll->advance_deductions = (string) round($advances, 2);
        $payroll->fine_deductions = (string) round($fineDeductions, 2);
        $payroll->expense_deductions = (string) round($expenseDeductions, 2);
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
     * Monthly salary pro-rated by presence (spec C4):
     *
     *   working_days     = Mon–Fri count of the FULL payroll month (divisor)
     *   days_present     = distinct WEEKDAYS with a worked-status or leave row
     *                      (approved leave counts as present), from the
     *                      joining date onward
     *   base             = base_salary ÷ working_days × days_present
     *
     * A mid-month joiner can only be present from their joining date, so their
     * salary pro-rates naturally against the full-month divisor. Weekend work
     * is NOT part of this line — a weekend row's own total (offer premium)
     * flows through the normal attendance buckets on top.
     *
     * @param  Collection<int, Attendance>  $records  the month's attendance rows
     */
    private function monthlyProRata(Employee $employee, string $month, Collection $records): float
    {
        $base = (float) ($employee->getAttribute('base_salary') ?? 0);

        if ($base <= 0) {
            return 0.0;
        }

        [$start, $end] = $this->bounds($month);
        $cursor = Carbon::parse($start);
        $last = Carbon::parse($end);

        $workingDays = 0;
        while ($cursor->lte($last)) {
            if ($cursor->isWeekday()) {
                $workingDays++;
            }
            $cursor->addDay();
        }

        if ($workingDays === 0) {
            return round($base, 2);
        }

        $joining = $employee->joining_date !== null
            ? Carbon::parse((string) $employee->joining_date)->startOfDay()
            : null;

        $present = $records
            ->filter(fn (Attendance $r) => in_array($r->status->value, self::WORKED, true)
                || $r->status === AttendanceStatus::Leave)
            ->filter(fn (Attendance $r) => $r->date->isWeekday())
            ->filter(fn (Attendance $r) => $joining === null || ! $r->date->lt($joining))
            ->map(fn (Attendance $r) => $r->date->toDateString())
            ->unique()
            ->count();

        return round($base / $workingDays * min($present, $workingDays), 2);
    }

    /**
     * Break the month's worked days into contiguous rate periods, so a payslip
     * for a worker whose rate changed mid-month shows each stretch separately:
     *
     *   Período 1: 01 Jul → 10 Jul  (50,00 €/día)  8 días   400,00 €
     *   Período 2: 11 Jul → 31 Jul  (70,00 €/día)  15 días  1.050,00 €
     *
     * A new period starts whenever the frozen rate on the day changes. Amounts
     * are the SAME base figure the gross uses (hours × frozen hourly), so the
     * periods always reconcile to days_amount / hours_amount to the cent.
     *
     * Returns null unless there are at least two periods — a single-rate month
     * needs no breakdown. Only daily/hourly workers have per-day rate periods.
     *
     * @param  Collection<int, Attendance>  $records
     * @return list<array<string, mixed>>|null
     */
    private function ratePeriods(Collection $records, ?WageType $wageType): ?array
    {
        if ($wageType !== WageType::Daily && $wageType !== WageType::Hourly) {
            return null;
        }

        $worked = $records
            ->filter(fn (Attendance $r) => in_array($r->status->value, self::WORKED, true))
            ->filter(fn (Attendance $r) => $r->hourly_rate_snapshot !== null)
            ->sortBy(fn (Attendance $r) => $r->date->toDateString())
            ->values();

        if ($worked->isEmpty()) {
            return null;
        }

        $periods = [];
        $current = null;
        $lastKey = null;

        foreach ($worked as $record) {
            $snapshotType = $record->wage_type_snapshot;
            $type = $snapshotType !== null ? $snapshotType->value : $wageType->value;
            $hourly = (float) $record->hourly_rate_snapshot;
            $key = $type.'|'.number_format($hourly, 4, '.', '');
            $date = $record->date->toDateString();
            $base = (float) $record->hours_worked * $hourly;

            if ($key !== $lastKey) {
                if ($current !== null) {
                    $periods[] = $current;
                }

                // Display rate: a daily worker's day-rate is the hourly × 8.
                $displayRate = $type === WageType::Daily->value ? round($hourly * 8, 2) : round($hourly, 2);

                $current = [
                    'wage_type' => $type,
                    'rate' => $displayRate,
                    'from' => $date,
                    'to' => $date,
                    'days' => 0,
                    'hours' => 0.0,
                    'amount' => 0.0,
                ];
                $lastKey = $key;
            }

            $current['to'] = $date;
            $current['days']++;
            $current['hours'] = round($current['hours'] + (float) $record->hours_worked, 2);
            $current['amount'] = round($current['amount'] + $base, 2);
        }

        if ($current !== null) {
            $periods[] = $current;
        }

        return count($periods) >= 2 ? $periods : null;
    }

    /**
     * The per-day-type breakdown for the payslip, grouped by (day type, rate)
     * so a mid-month rate change splits into its own line:
     *
     *   Jornadas completas: 15 días × 80,00 €  = 1.200,00 €
     *   Medias jornadas:     4 días × 40,00 €  =   160,00 €
     *   Por horas:          12 h    × 10,00 €  =   120,00 €
     *   Por metros:         30 m    ×  5,00 €  =   150,00 €
     *
     * `units` is days / hours / metres by type; `rate` is the price per unit
     * (a half day shows the half-day rate). Amounts sum to the attendance
     * earnings exactly — they are the frozen day totals, never recomputed.
     *
     * @param  Collection<int, Attendance>  $paidRows
     * @return list<array<string, mixed>>|null
     */
    private function dayTypeSummary(Collection $paidRows, Employee $employee): ?array
    {
        $order = ['full' => 0, 'half' => 1, 'hourly' => 2, 'per_meter' => 3];
        $groups = [];

        foreach ($paidRows as $record) {
            $type = $this->effectiveDayType($record);
            $weekend = (bool) $record->is_weekend;
            $perMeterRate = (float) ($record->wage_rate_snapshot ?? 0);
            $hourly = (float) ($record->hourly_rate_snapshot ?? 0);
            // Same re-derivation as the gross, so a back-fill-broken jornada row
            // shows its real amount here too rather than 0.
            $total = $this->rowAmount($record, $employee);

            // full/half price per unit is the day's own total (units = 1), so a
            // rate change splits into its own group and a leave row with no rate
            // snapshot still shows the amount it actually paid.
            [$unitRate, $units, $amount] = match ($type) {
                DayType::Full, DayType::Half => [$total, 1.0, $total],
                DayType::PerMeter => [$perMeterRate, (float) ($record->quantity ?? 0), $total],
                DayType::Hourly => [$hourly, (float) $record->hours_worked, (float) $record->hours_worked * $hourly],
            };

            // Weekend days get their own line ("Días fin de semana").
            $key = $type->value.'|'.($weekend ? 'w' : 'd').'|'.number_format($unitRate, 4, '.', '');
            $groups[$key] ??= ['type' => $type->value, 'weekend' => $weekend, 'rate' => round($unitRate, 2), 'units' => 0.0, 'amount' => 0.0];
            $groups[$key]['units'] = round($groups[$key]['units'] + $units, 2);
            $groups[$key]['amount'] = round($groups[$key]['amount'] + $amount, 2);
        }

        // Drop empty lines (e.g. an open check-in with 0 hours) so the payslip
        // shows only day types that actually paid something.
        $groups = array_filter($groups, fn (array $g): bool => $g['units'] > 0 || $g['amount'] > 0);

        if ($groups === []) {
            return null;
        }

        $list = array_values($groups);
        usort($list, fn (array $a, array $b): int => ($order[$a['type']] <=> $order[$b['type']])
            ?: (($a['weekend'] <=> $b['weekend']) ?: ($b['rate'] <=> $a['rate'])));

        return $list;
    }

    /**
     * The amount a worked day contributes. Normally the frozen total_amount —
     * which the freeze tests pin (a raise never rewrites a priced day). But a
     * daily (full/half) row left at 0 by the day-type back-fill migration (its
     * old hourly-priced total was 0 and the reclassification never re-priced it)
     * would silently pay nothing. For that broken case ONLY — total is 0 and the
     * row is not a manual override — re-derive the jornada from the daily rate in
     * force on that date (wage history, else the live field). Correctly-priced
     * rows (total ≠ 0) are untouched, so freeze behaviour is unchanged.
     */
    private function rowAmount(Attendance $record, Employee $employee): float
    {
        $total = (float) $record->total_amount;

        if ($total !== 0.0 || $record->manual_wage_override) {
            return $total;
        }

        $type = $this->effectiveDayType($record);

        if ($type === DayType::Full || $type === DayType::Half) {
            $daily = (float) ($this->wageRates->ratesForDate($employee, $record->date)['daily'] ?? 0);

            return $type === DayType::Half ? round($daily * 0.5, 2) : $daily;
        }

        return $total;
    }

    /**
     * The day type a row is priced by. Explicit `day_type` wins; a row without
     * one (a leave row, or legacy data) falls back to its frozen wage type —
     * a daily snapshot is a full jornada, an hourly snapshot is priced per hour.
     */
    private function effectiveDayType(Attendance $record): DayType
    {
        if ($record->day_type !== null) {
            return $record->day_type;
        }

        return match ($record->wage_type_snapshot) {
            WageType::Daily => DayType::Full,
            WageType::PerMeter => DayType::PerMeter,
            default => DayType::Hourly,
        };
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
     *
     * Only expenses the WORKER bears count (`is_reimbursable`, derived from
     * bearable_by=employee & not salary-deducted). A client-borne expense on a
     * project is billed to the client via the invoice, and a company-borne one
     * is a company cost — neither is money owed back to the worker. Without this
     * gate a client expense would be double-paid (reimbursed AND invoiced).
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
            ->where('is_reimbursable', true)
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
     * Company-card / employee costs the admin flagged as recoverable from the
     * worker (`deduct_from_salary`) — they come OFF this month's pay. Approved
     * only, mirroring the reimbursement gate.
     */
    private function expenseSalaryDeductionsFor(int $employeeId, string $month): float
    {
        [$start, $end] = $this->bounds($month);

        return round((float) Expense::query()->withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('deduct_from_salary', true)
            ->where('approved', true)
            ->whereBetween('date', [$start, $end])
            ->sum('total'), 2);
    }

    /**
     * Approved worker-PWA expenses submitted for this month. Folded into the
     * reimbursements line on the payslip.
     */
    private function pwaExpensesFor(int $employeeId, string $month): float
    {
        [$start, $end] = $this->bounds($month);

        return round((float) WorkerExpense::query()->withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('status', WorkerExpenseStatus::Approved->value)
            ->whereNull('payroll_id')
            ->whereBetween('date', [$start, $end])
            ->sum('amount'), 2);
    }

    /**
     * Fuel a worker paid out of pocket in a company vehicle and asked to be paid
     * back — a fuel record marked `payment_method = 'reimburse'`. Fuel put on the
     * company card (any other method) is a company cost and never touches pay.
     */
    private function fuelReimbursementsFor(int $employeeId, string $month): float
    {
        [$start, $end] = $this->bounds($month);

        return round((float) VehicleFuelRecord::query()->withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('payment_method', 'reimburse')
            ->whereBetween('fuel_date', [$start, $end])
            ->sum('total_cost'), 2);
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
     * Vehicle fines deducted from this month's pay. A fine NEVER auto-deducts:
     * the admin must explicitly flag it "deduct from salary" and pick the month
     * (VehicleFineController::deductFromSalary), which sets deduct_from_salary +
     * deduction_month. Only those fines, for this employee and month, are taken.
     */
    private function vehicleFinesFor(int $employeeId, string $month): float
    {
        return round((float) VehicleFine::query()->withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('deduct_from_salary', true)
            ->where('deduction_month', $month)
            ->sum('amount'), 2);
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
