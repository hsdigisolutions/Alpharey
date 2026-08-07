<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceMode;
use App\Enums\DayType;
use App\Enums\DeploymentStatus;
use App\Enums\OvertimePolicyType;
use App\Enums\WageType;
use App\Enums\WeekendRateType;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\OvertimePolicy;
use App\Models\Scopes\CompanyScope;
use App\Services\Employees\WageRateService;
use App\Support\CurrentCompany;
use App\Support\PeriodLock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Attendance create/update pipeline. Two responsibilities that must stay
 * correct for payroll (Phase 6):
 *
 *  1. WAGE SNAPSHOTS — freeze the employee's wage_type + rate at entry time,
 *     so a later raise never rewrites historical pay.
 *  2. TOTALS — compute hours (hourly mode) and the day amount from the
 *     snapshot + the employee's overtime policy, unless manually overridden.
 *
 * Once a month is locked (Phase 6), writes into it are rejected here — that is
 * what makes the lock hold "system-wide" rather than only on the payroll screen.
 */
class AttendanceService
{
    public function __construct(
        private readonly PeriodLock $lock,
        private readonly WageRateService $wageRates,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Attendance
    {
        return DB::transaction(function () use ($data): Attendance {
            $employee = $this->resolveEmployee((int) $data['employee_id']);

            $attendance = new Attendance($data);
            $attendance->company_id = app(CurrentCompany::class)->id();

            if ($attendance->company_id !== null) {
                $this->lock->assertOpen($attendance->company_id, $attendance->date);
            }

            $this->applySnapshots($attendance, $employee);
            $this->recompute($attendance);
            $attendance->save();

            $this->log($attendance, 'created');

            return $attendance;
        });
    }

    /**
     * Create an attendance row for a worker punch. The employee is already
     * verified by the WorkerController — bypass resolveEmployee() which
     * requires a CRM session (CurrentCompany) that workers do not have.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForWorker(Employee $employee, array $data): Attendance
    {
        return DB::transaction(function () use ($employee, $data): Attendance {
            $attendance = new Attendance($data);
            $attendance->company_id = $employee->company_id;

            $this->lock->assertOpen($employee->company_id, $attendance->date);

            $this->applySnapshots($attendance, $employee);
            $this->recompute($attendance);
            $attendance->save();

            $this->log($attendance, 'created');

            return $attendance;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Attendance $attendance, array $data): Attendance
    {
        return DB::transaction(function () use ($attendance, $data): Attendance {
            // Guard both the month it is in now and the month it would move to.
            $this->lock->assertOpen($attendance->company_id, $attendance->date);

            $attendance->fill($data);

            $this->lock->assertOpen($attendance->company_id, $attendance->date);

            // Re-freeze the snapshot if the employee, the date, or the DAY TYPE
            // changed — each picks a different rate. An otherwise-unchanged row
            // keeps its original frozen rate.
            if ($attendance->isDirty('employee_id') || $attendance->isDirty('date') || $attendance->isDirty('day_type')) {
                $employee = $this->resolveEmployee((int) $attendance->employee_id);
                $this->applySnapshots($attendance, $employee);
            }

            $this->recompute($attendance);
            $attendance->save();

            $this->log($attendance, 'updated');

            return $attendance;
        });
    }

    /**
     * The employee whose day is being logged: one of OUR OWN, or one deployed
     * INTO the acting company (Phase 5) — whose row lives under their HOME
     * company, where the tenant-scoped lookup this used to be could never see
     * it. The grid showed deployed workers but saving a cell for one 404'd.
     * Anyone else stays a 404.
     */
    private function resolveEmployee(int $employeeId): Employee
    {
        $employee = Employee::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->findOrFail($employeeId);

        $companyId = app(CurrentCompany::class)->id();

        if ($employee->company_id === $companyId) {
            return $employee;
        }

        $deployedHere = EmployeeDeployment::query()
            ->where('employee_id', $employee->id)
            ->where('host_company_id', $companyId)
            ->whereIn('status', [DeploymentStatus::Active->value, DeploymentStatus::Completed->value])
            ->exists();

        abort_unless($deployedHere, 404);

        return $employee;
    }

    /**
     * Re-freeze the snapshot and recompute the day total for an existing row,
     * used when a back-dated wage change reprices UNPAID attendance
     * (WageRateService::recalculateUnpaidAttendance). Paid/locked days are
     * filtered out by the caller and never reach here.
     */
    public function recalculateRow(Attendance $attendance, Employee $employee): void
    {
        $this->applySnapshots($attendance, $employee);
        $this->recompute($attendance);
        $attendance->save();

        $this->log($attendance, 'updated');
    }

    /**
     * Freeze the rate this day is priced from — chosen by its DAY TYPE, using
     * the rates in force for the date (WageRateService respects wage history):
     *
     *   full/half → the daily rate (hourly equivalent = daily/8 for OT)
     *   hourly    → the hourly rate
     *   per_meter → the per-meter rate
     *
     * The frozen rate never moves once set; a later raise cannot rewrite it.
     */
    private function applySnapshots(Attendance $attendance, Employee $employee): void
    {
        $dayType = $this->dayTypeFor($attendance);
        $attendance->day_type = $dayType;

        $rates = $this->wageRates->ratesForDate($employee, $attendance->date);

        [$wageType, $rate, $hourly] = match ($dayType) {
            DayType::Hourly => [WageType::Hourly, $rates['hourly'], $rates['hourly']],
            DayType::PerMeter => [WageType::PerMeter, $rates['per_meter'], null],
            // full / half are both daily-rate jornadas
            default => [
                WageType::Daily,
                $rates['daily'],
                $rates['daily'] !== null ? round($rates['daily'] / 8, 2) : null,
            ],
        };

        $attendance->wage_type_snapshot = $wageType;
        $attendance->wage_rate_snapshot = $rate;
        $attendance->hourly_rate_snapshot = $hourly;
    }

    /**
     * The day type a row is priced by. Explicit when set; otherwise derived
     * from the capture mode (hourly clock → hourly, anything else → a full day,
     * the dehadi default) so legacy and worker-PWA rows still price.
     */
    private function dayTypeFor(Attendance $attendance): DayType
    {
        return $attendance->day_type
            ?? ($attendance->mode === AttendanceMode::Hourly ? DayType::Hourly : DayType::Full);
    }

    /**
     * Recompute hours (hourly mode) and the day total from the frozen rate and
     * the DAY TYPE. Manual override keeps the caller-supplied total_amount.
     *
     *   full      total = daily rate
     *   half      total = daily rate × 0.5
     *   hourly    total = hours × hourly rate  (+ overtime)
     *   per_meter total = quantity × per-meter rate
     */
    private function recompute(Attendance $attendance): void
    {
        // Weekend is detected server-side from the date, never taken from input.
        $attendance->is_weekend = $attendance->date->isWeekend();

        // Columns are decimal casts → assign numeric strings (matches @property)
        if ($attendance->mode === AttendanceMode::Hourly) {
            $attendance->hours_worked = (string) $this->hoursFromClock($attendance);
        }

        if ($attendance->manual_wage_override) {
            return; // trust the caller's total_amount
        }

        $rate = (float) ($attendance->wage_rate_snapshot ?? 0);

        $total = match ($this->dayTypeFor($attendance)) {
            DayType::Full => $rate,
            DayType::Half => $rate * 0.5,
            DayType::PerMeter => (float) ($attendance->quantity ?? 0) * $rate,
            DayType::Hourly => $this->hourlyTotal($attendance),
        };

        $total = $this->applyWeekendPremium($attendance, $total);

        $attendance->total_amount = (string) round($total, 2);
    }

    /**
     * A voluntary weekend day can be paid at a premium: × 1.5, × 2, or a flat
     * custom amount. Only applies when the day is actually a weekend and a rate
     * type is set — a weekday, or a weekend with no premium, is unchanged.
     */
    private function applyWeekendPremium(Attendance $attendance, float $total): float
    {
        if (! $attendance->is_weekend || $attendance->weekend_rate_type === null) {
            return $total;
        }

        if ($attendance->weekend_rate_type === WeekendRateType::Custom) {
            return (float) ($attendance->weekend_rate_amount ?? 0);
        }

        return $total * ($attendance->weekend_rate_type->multiplier() ?? 1.0);
    }

    /**
     * Hourly-day total: worked hours + priced overtime, from the frozen rate.
     */
    private function hourlyTotal(Attendance $attendance): float
    {
        $hourly = (float) ($attendance->hourly_rate_snapshot ?? 0);
        $base = (float) $attendance->hours_worked * $hourly;
        $ot = (float) $attendance->overtime_hours * $hourly * $this->overtimeMultiplier($attendance);

        return $base + $ot;
    }

    private function hoursFromClock(Attendance $attendance): float
    {
        if ($attendance->check_in === null || $attendance->check_out === null) {
            return (float) $attendance->hours_worked;
        }

        [$inH, $inM] = array_map('intval', explode(':', $attendance->check_in));
        [$outH, $outM] = array_map('intval', explode(':', $attendance->check_out));

        $minutes = ($outH * 60 + $outM) - ($inH * 60 + $inM);
        $hours = max(0, $minutes / 60);

        if ($attendance->deduct_break) {
            $hours = max(0, $hours - (float) $attendance->break_hours);
        }

        return round($hours, 2);
    }

    /**
     * Overtime pay multiplier from the employee's overtime policy.
     * percentage → 1 + rate/100; fixed_hourly is handled by callers that
     * price OT separately; accumulate/none → OT is not paid as cash (1.0 base
     * still applies for the worked hours, OT cash contribution is 0).
     */
    private function overtimeMultiplier(Attendance $attendance): float
    {
        // Tenant scope dropped: a deployed worker's row lives under their HOME
        // company, and a scoped lookup silently priced their OT at the default
        // instead of their policy. The id comes off the row, never from input.
        $employee = $attendance->relationLoaded('employee')
            ? $attendance->employee
            : Employee::query()->withoutGlobalScope(CompanyScope::class)->find($attendance->employee_id);

        // Same cross-scope rule: the policy is the EMPLOYEE's own and lives
        // under their home company when they are deployed here.
        $policy = $employee?->overtime_policy_id !== null
            ? OvertimePolicy::query()->withoutGlobalScope(CompanyScope::class)->find($employee->overtime_policy_id)
            : null;

        if ($policy === null) {
            return 1.5; // sensible default OT multiplier when no policy set
        }

        return match ($policy->type) {
            OvertimePolicyType::Percentage => 1 + ((float) $policy->rate / 100),
            OvertimePolicyType::FixedHourly => (float) $policy->rate > 0 && $attendance->hourly_rate_snapshot > 0
                ? (float) $policy->rate / (float) $attendance->hourly_rate_snapshot
                : 1.5,
            default => 0.0, // accumulate_days / none: OT not paid as cash
        };
    }

    private function log(Attendance $attendance, string $action): void
    {
        AttendanceLog::query()->create([
            'attendance_id' => $attendance->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'changes' => $attendance->getChanges(),
            'created_at' => now(),
        ]);
    }
}
