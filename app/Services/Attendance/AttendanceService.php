<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceMode;
use App\Enums\OvertimePolicyType;
use App\Enums\WageType;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\OvertimePolicy;
use App\Support\CurrentCompany;
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
 */
class AttendanceService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Attendance
    {
        return DB::transaction(function () use ($data): Attendance {
            $employee = Employee::query()->findOrFail($data['employee_id']);

            $attendance = new Attendance($data);
            $attendance->company_id = app(CurrentCompany::class)->id();
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
            $attendance->fill($data);

            // Re-freeze the snapshot only if the employee changed; otherwise
            // the original day-rate stands.
            if ($attendance->isDirty('employee_id')) {
                $employee = Employee::query()->findOrFail($attendance->employee_id);
                $this->applySnapshots($attendance, $employee);
            }

            $this->recompute($attendance);
            $attendance->save();

            $this->log($attendance, 'updated');

            return $attendance;
        });
    }

    private function applySnapshots(Attendance $attendance, Employee $employee): void
    {
        $attendance->wage_type_snapshot = $employee->wage_type;

        $rate = $employee->getAttribute('wage_rate');
        $attendance->wage_rate_snapshot = $rate !== null ? (float) $rate : null;

        // Resolve an hourly rate for the day: explicit hourly rate, or derive
        // a daily wage over the employee's default working hours.
        $attendance->hourly_rate_snapshot = $this->resolveHourlyRate($employee);
    }

    private function resolveHourlyRate(Employee $employee): ?float
    {
        $rate = $employee->getAttribute('wage_rate');
        $daily = $employee->getAttribute('daily_wage');

        return match ($employee->wage_type) {
            WageType::Hourly => $rate !== null ? (float) $rate : null,
            WageType::Daily => $daily !== null ? round((float) $daily / 8, 2) : null,
            default => $rate !== null ? (float) $rate : null,
        };
    }

    /**
     * Recompute hours (hourly mode) and the day total. Manual override keeps
     * the caller-supplied total_amount untouched.
     */
    private function recompute(Attendance $attendance): void
    {
        // Columns are decimal casts → assign numeric strings (matches @property)
        if ($attendance->mode === AttendanceMode::Hourly) {
            $attendance->hours_worked = (string) $this->hoursFromClock($attendance);
        }

        if ($attendance->manual_wage_override) {
            return; // trust the caller's total_amount
        }

        $hourly = (float) ($attendance->hourly_rate_snapshot ?? 0);
        $hours = (float) $attendance->hours_worked;
        $otHours = (float) $attendance->overtime_hours;

        $base = $hours * $hourly;
        $ot = $otHours * $hourly * $this->overtimeMultiplier($attendance);

        $attendance->total_amount = (string) round($base + $ot, 2);
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
        $employee = $attendance->relationLoaded('employee')
            ? $attendance->employee
            : Employee::query()->find($attendance->employee_id);

        $policy = $employee?->overtime_policy_id !== null
            ? OvertimePolicy::query()->find($employee->overtime_policy_id)
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
