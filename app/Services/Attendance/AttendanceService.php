<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceMode;
use App\Enums\DayType;
use App\Enums\DeploymentStatus;
use App\Enums\OvertimePolicyType;
use App\Enums\PayrollStatus;
use App\Enums\WageType;
use App\Enums\WeekendRateType;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\OvertimePolicy;
use App\Models\Payroll;
use App\Models\Scopes\CompanyScope;
use App\Services\Employees\WageRateService;
use App\Services\Settings\SettingsService;
use App\Support\CurrentCompany;
use App\Support\PeriodLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    /** Default day-type thresholds (hours), overridable per company in Settings. */
    public const DEFAULT_FULL_DAY_THRESHOLD = 6.0;

    public const DEFAULT_HALF_DAY_THRESHOLD = 3.0;

    /** Max check-out distance (metres) from check-in before an alert — per company. */
    public const DEFAULT_MAX_LOCATION_DISTANCE = 500.0;

    /** Standard unpaid break (minutes) deducted from a full-day shift's displayed hours. */
    public const DEFAULT_BREAK_MINUTES = 60;

    /** Distance (metres) from the PROJECT site above which a check-in is "off site" — per company. */
    public const DEFAULT_OFF_SITE_ALERT_DISTANCE = 2000;

    public function __construct(
        private readonly PeriodLock $lock,
        private readonly WageRateService $wageRates,
        private readonly SettingsService $settings,
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
            $this->assertMonthNotPaid((int) $attendance->employee_id, $attendance->date);

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
            $this->assertMonthNotPaid($employee->id, $attendance->date);

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
            $this->assertMonthNotPaid((int) $attendance->employee_id, $attendance->date);

            $attendance->fill($data);

            // A human is now editing this row — it is no longer an auto-absence.
            $attendance->is_auto_generated = false;

            // An explicit day_type in the payload is a MANUAL choice (the admin
            // grid/modal always sends it), so the auto-detected grade no longer
            // stands — this becomes an override. auto_day_type is kept as the
            // record of what the system had detected.
            if (array_key_exists('day_type', $data)) {
                $attendance->is_auto_detected = false;
            }

            // …and the month/employee the row would MOVE to.
            $this->lock->assertOpen($attendance->company_id, $attendance->date);
            $this->assertMonthNotPaid((int) $attendance->employee_id, $attendance->date);

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
     * Paid records are NEVER touched (spec C5/C10). A month whose payroll for
     * this employee is already PAID rejects every attendance write — creating,
     * editing, or moving a row into it — even before the period is formally
     * locked. Without this, a Paid-but-unlocked month could silently diverge
     * from the payslip that was already paid out.
     */
    public function assertMonthNotPaid(int $employeeId, Carbon|string|null $date): void
    {
        if ($date === null) {
            return;
        }

        $month = Carbon::parse($date)->format('Y-m');

        $paid = Payroll::query()->withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('month', $month)
            ->where('status', PayrollStatus::Paid)
            ->exists();

        if ($paid) {
            throw ValidationException::withMessages([
                'date' => __('ui.attendance.month_paid'),
            ]);
        }
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
     * Grade a day from the hours worked, using the company's thresholds:
     *
     *   hours >= full threshold  → full   (rate × 1.0)
     *   hours >= half threshold  → half   (rate × 0.5)
     *   otherwise                → hourly (hourly rate × hours)
     */
    public function autoDayType(float $hours, int $companyId): DayType
    {
        $t = $this->dayTypeThresholds($companyId);

        return match (true) {
            $hours >= $t['full'] => DayType::Full,
            $hours >= $t['half'] => DayType::Half,
            default => DayType::Hourly,
        };
    }

    /**
     * The company's day-type thresholds (hours). A per-company setting wins over
     * the group default, which in turn falls back to the coded default.
     *
     * @return array{full: float, half: float}
     */
    public function dayTypeThresholds(int $companyId): array
    {
        return [
            'full' => (float) $this->settings->get(
                "attendance.full_day_threshold.{$companyId}",
                $this->settings->get('attendance.full_day_threshold', self::DEFAULT_FULL_DAY_THRESHOLD),
            ),
            'half' => (float) $this->settings->get(
                "attendance.half_day_threshold.{$companyId}",
                $this->settings->get('attendance.half_day_threshold', self::DEFAULT_HALF_DAY_THRESHOLD),
            ),
        ];
    }

    /**
     * The company's maximum allowed check-out distance from check-in (metres).
     * Per-company setting wins over the group default, then the coded default.
     */
    public function maxLocationDistance(int $companyId): float
    {
        return (float) $this->settings->get(
            "attendance.max_location_distance.{$companyId}",
            $this->settings->get('attendance.max_location_distance', self::DEFAULT_MAX_LOCATION_DISTANCE),
        );
    }

    /**
     * Distance (metres) from the project site above which a check-in counts as
     * "off site" and raises an alert — per company, Settings-driven (Screen 26).
     */
    public function offSiteAlertDistance(int $companyId): int
    {
        return (int) $this->settings->get(
            "attendance.off_site_alert_distance.{$companyId}",
            $this->settings->get('attendance.off_site_alert_distance', self::DEFAULT_OFF_SITE_ALERT_DISTANCE),
        );
    }

    /**
     * The standard unpaid break (minutes) deducted from a full-day shift's
     * DISPLAYED hours (an 08:00–17:00 jornada shows 8 h, not 9). Per company,
     * Settings-driven. DISPLAY ONLY — it never rewrites hours_worked or touches
     * pay (full/half days are paid a fixed daily rate regardless of hours).
     */
    public function breakDurationMinutes(int $companyId): int
    {
        return (int) $this->settings->get(
            "attendance.break_duration_minutes.{$companyId}",
            $this->settings->get('attendance.break_duration_minutes', self::DEFAULT_BREAK_MINUTES),
        );
    }

    /**
     * The company's working days as ISO weekday numbers (1=Mon … 7=Sun).
     * Default Mon–Fri (1–5), i.e. Sat/Sun off — identical to the legacy
     * hardcoded weekend. Drives absence tracking (a non-working day is never
     * an absence). Weekend-premium pay + weekend work offers remain a separate
     * Sat/Sun concept and are unaffected by this setting.
     *
     * @return list<int>
     */
    public function workingDays(int $companyId): array
    {
        /** @var mixed $raw */
        $raw = $this->settings->get("attendance.working_days.{$companyId}", [1, 2, 3, 4, 5]);
        $days = is_array($raw) ? array_values(array_filter(array_map('intval', $raw), fn (int $d): bool => $d >= 1 && $d <= 7)) : [];

        return $days === [] ? [1, 2, 3, 4, 5] : $days;
    }

    /**
     * Set the day type AUTOMATICALLY from the hours already computed on the row,
     * then re-freeze the rate for that type and re-price. Called on check-out —
     * the worker never picks a type; an admin can still override later via
     * update() (which flips is_auto_detected off).
     */
    public function applyAutoDayType(Attendance $attendance, Employee $employee): void
    {
        // Weekend days reach here ONLY when the worker was invited via an offer
        // and actually came in, so they ARE graded like a weekday (full/half by
        // hours) — otherwise a full 10 h Saturday would price hours × hourly rate
        // instead of a full day. The weekend PREMIUM (the offer's rate) is then
        // layered on top by recompute()'s applyWeekendPremium().

        // Full/half grading prices from the DAILY (jornada) rate — it only makes
        // sense for a worker who has one. A purely hourly worker keeps their
        // hourly pricing regardless of hours (grading them to a "full day" would
        // price a daily rate they do not have).
        $rates = $this->wageRates->ratesForDate($employee, $attendance->date);
        $hasDailyRate = ($rates['daily'] ?? 0) > 0;

        $detected = $hasDailyRate
            ? $this->autoDayType((float) $attendance->hours_worked, (int) $attendance->company_id)
            : DayType::Hourly;

        $attendance->day_type = $detected;
        $attendance->auto_day_type = $detected;
        $attendance->is_auto_detected = true;

        // Re-freeze the rate for the detected type (full/half → daily rate) and
        // recompute the day total from it.
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
        // Salary-structure rule (2026-08-12): the worker is ALWAYS paid their
        // profile / wage-history rate. Project designation rates are CLIENT
        // BILLING data — client_rate feeds P&L income, worker_rate is reference
        // only — and must never reach the pay snapshot. (Rows frozen under the
        // pre-rule behaviour keep their historical totals; history is never
        // rewritten.)
        $dayType = $this->dayTypeFor($attendance);
        $attendance->day_type = $dayType;

        $rates = $this->wageRates->ratesForDate($employee, $attendance->date);

        // Partial-day fallback (spec C3/C4): a daily worker with no hourly rate
        // on a partial day is priced at daily_wage ÷ 8 × hours — never 0 €.
        $partialHourly = $rates['hourly']
            ?? ($rates['daily'] !== null ? round($rates['daily'] / 8, 2) : null);

        [$wageType, $rate, $hourly] = match ($dayType) {
            DayType::Hourly => [WageType::Hourly, $partialHourly, $partialHourly],
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
