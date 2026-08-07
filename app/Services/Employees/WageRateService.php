<?php

namespace App\Services\Employees;

use App\Enums\PayrollStatus;
use App\Enums\WageType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeWageRate;
use App\Models\Payroll;
use App\Models\Scopes\CompanyScope;
use App\Services\Attendance\AttendanceService;
use App\Support\PeriodLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single authority for an employee's effective-dated wage history.
 *
 * The history is a sequence of EmployeeWageRate rows, each owning a closed
 * [effective_from, effective_to] range, with exactly one open row (effective_to
 * = null) per employee. This service is the ONLY writer that keeps that
 * invariant — EmployeeService and the "Nueva Tarifa" flow both go through it.
 *
 *  - rateForDate()  → the rate in force on a given day (the spec's lookup).
 *  - snapshotValues() → what AttendanceService freezes onto each worked day.
 *  - createRate()   → open a new dated rate, close the previous one, sync the
 *                     employee's cached wage fields, and recalculate any UNPAID
 *                     attendance the change now reprices (paid days never move).
 *
 * A worked day is always priced from the rate frozen on it, never the live
 * rate — so a raise in August cannot rewrite July's pay.
 */
class WageRateService
{
    public function __construct(private readonly PeriodLock $lock) {}

    /**
     * The rate record in force for this employee on the given date, or null if
     * none covers it. Unscoped by company: a deployed worker's rates live under
     * their home company, and the employee_id already pins the row to one person.
     */
    public function rateForDate(int $employeeId, string $date): ?EmployeeWageRate
    {
        return EmployeeWageRate::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The three snapshot values AttendanceService freezes onto a worked day:
     * [wage_type, wage_rate, hourly_rate].
     *
     * If a dated rate covers the day, it wins. Otherwise we fall back to the
     * employee's live wage fields — replicating the pre-history behaviour
     * exactly, so an employee created before any rate row (e.g. via factory)
     * still prices correctly.
     *
     * @return array{0: WageType|null, 1: float|null, 2: float|null}
     */
    public function snapshotValues(Employee $employee, Carbon|string $date): array
    {
        $day = $date instanceof Carbon ? $date->toDateString() : $date;
        $record = $this->rateForDate($employee->id, $day);

        if ($record !== null && $record->wage_type !== null) {
            $type = $record->wage_type;
            $rate = (float) $record->rate;

            return [$type, $rate, $this->hourlyFor($type, $rate)];
        }

        // Fallback: the live employee fields, matching the original snapshot.
        $liveRate = $employee->getAttribute('wage_rate');
        $wageRate = $liveRate !== null ? (float) $liveRate : null;

        return [$employee->wage_type, $wageRate, $this->fallbackHourly($employee)];
    }

    /**
     * The three pay rates in force for this employee on the given date, for the
     * day-type calculation: [daily, hourly, per_meter].
     *
     * The dated wage history governs whichever rate matches its own wage_type
     * (a dehadi's daily rate changing over time); the other two come from the
     * employee's live rate columns — "admin fills whichever applies".
     *
     * @return array{daily: float|null, hourly: float|null, per_meter: float|null}
     */
    public function ratesForDate(Employee $employee, Carbon|string $date): array
    {
        $day = $date instanceof Carbon ? $date->toDateString() : $date;
        $record = $this->rateForDate($employee->id, $day);

        $daily = $this->floatOrNull($employee->getAttribute('daily_wage'));
        $hourly = $this->floatOrNull($employee->getAttribute('wage_rate'));
        $perMeter = $this->floatOrNull($employee->getAttribute('per_meter_rate'));

        if ($record !== null && $record->wage_type !== null) {
            $rate = (float) $record->rate;
            match ($record->wage_type) {
                WageType::Daily => $daily = $rate,
                WageType::Hourly => $hourly = $rate,
                WageType::PerMeter => $perMeter = $rate,
                WageType::Monthly => null, // monthly salary is not a per-day rate
            };
        }

        return ['daily' => $daily, 'hourly' => $hourly, 'per_meter' => $perMeter];
    }

    /**
     * Open a new dated rate for the employee. Closes the currently-open rate the
     * day before the new one starts, syncs the employee's cached wage fields to
     * whatever rate is active today, and reprices UNPAID attendance from the new
     * date onward. Paid or locked days are never touched.
     *
     * @param  array{effective_from: string, wage_type: string, rate: numeric-string|float, reason?: string|null}  $data
     */
    public function createRate(Employee $employee, array $data): EmployeeWageRate
    {
        return DB::transaction(function () use ($employee, $data): EmployeeWageRate {
            $from = $data['effective_from'];

            $open = EmployeeWageRate::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('employee_id', $employee->id)
                ->whereNull('effective_to')
                ->orderByDesc('effective_from')
                ->first();

            // A new rate must start strictly after the current one — you cannot
            // back-date behind the rate that is presently open.
            if ($open !== null && $from <= $open->effective_from->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_from' => __('ui.wage_rates.date_after_current', [
                        'date' => $open->effective_from->toDateString(),
                    ]),
                ]);
            }

            if ($open !== null) {
                $open->effective_to = Carbon::parse($from)->subDay();
                $open->is_default = false;
                $open->save();
            }

            $rate = new EmployeeWageRate([
                'wage_type' => $data['wage_type'],
                'rate' => (string) $data['rate'],
                'effective_from' => $from,
                'is_default' => true,
                'reason' => $data['reason'] ?? null,
            ]);
            $rate->effective_to = null;
            $rate->employee_id = $employee->id;
            $rate->company_id = $employee->company_id;
            $rate->created_by = Auth::id();
            $rate->save();

            // Keep the employee's cached wage fields in step with today's rate.
            $this->syncEmployeeToActiveRate($employee);

            // Reprice unpaid days the new rate now governs (edge case 2).
            $this->recalculateUnpaidAttendance($employee, $from);

            return $rate;
        });
    }

    /**
     * Delete a rate record — refused if any attendance falls in its range, since
     * those days were priced from it. Re-opens the previous rate if the deleted
     * one was the open (current) record, so the invariant holds.
     */
    public function deleteRate(EmployeeWageRate $rate): void
    {
        $end = $rate->effective_to?->toDateString();

        $hasAttendance = Attendance::query()->withoutGlobalScopes()
            ->where('employee_id', $rate->employee_id)
            ->where('date', '>=', $rate->effective_from->toDateString())
            ->when($end !== null, fn ($q) => $q->where('date', '<=', $end))
            ->exists();

        if ($hasAttendance) {
            throw ValidationException::withMessages([
                'wage_rate' => __('ui.wage_rates.has_attendance'),
            ]);
        }

        DB::transaction(function () use ($rate): void {
            $wasOpen = $rate->effective_to === null;
            $employeeId = $rate->employee_id;
            $rate->delete();

            if ($wasOpen) {
                $previous = EmployeeWageRate::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('employee_id', $employeeId)
                    ->orderByDesc('effective_from')
                    ->orderByDesc('id')
                    ->first();

                if ($previous !== null) {
                    $previous->effective_to = null;
                    $previous->is_default = true;
                    $previous->save();
                }
            }

            $employee = Employee::query()->withoutGlobalScope(CompanyScope::class)->find($employeeId);
            if ($employee !== null) {
                $this->syncEmployeeToActiveRate($employee);
            }
        });
    }

    /**
     * Seed the first rate for a freshly-created employee. Idempotent: does
     * nothing if a rate already exists or no wage is set.
     */
    public function seedFromEmployee(Employee $employee): void
    {
        if ($employee->wage_type === null) {
            return;
        }

        $amount = $this->liveAmountFor($employee);
        if ($amount === null || $amount <= 0) {
            return;
        }

        $exists = EmployeeWageRate::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->exists();

        if ($exists) {
            return;
        }

        $rate = new EmployeeWageRate([
            'wage_type' => $employee->wage_type->value,
            'rate' => (string) $amount,
            'effective_from' => $employee->joining_date?->toDateString() ?? now()->toDateString(),
            'is_default' => true,
        ]);
        $rate->effective_to = null;
        $rate->employee_id = $employee->id;
        $rate->company_id = $employee->company_id;
        $rate->created_by = Auth::id();
        $rate->save();
    }

    /**
     * A direct edit of the employee's wage fields (the employee form, not the
     * dated "Nueva Tarifa" flow) is a correction to the CURRENT rate — update
     * the open record in place rather than opening a spurious same-day period.
     */
    public function syncOpenRateFromEmployee(Employee $employee): void
    {
        if ($employee->wage_type === null) {
            return;
        }

        $amount = $this->liveAmountFor($employee);
        if ($amount === null || $amount <= 0) {
            $this->seedFromEmployee($employee);

            return;
        }

        $open = EmployeeWageRate::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->whereNull('effective_to')
            ->orderByDesc('effective_from')
            ->first();

        if ($open === null) {
            $this->seedFromEmployee($employee);

            return;
        }

        $open->wage_type = $employee->wage_type;
        $open->rate = (string) $amount;
        $open->save();
    }

    /**
     * The employee's history, newest first, for the detail-page timeline.
     *
     * @return Collection<int, EmployeeWageRate>
     */
    public function history(Employee $employee): Collection
    {
        return EmployeeWageRate::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Reprice every UNPAID, unlocked attendance day from the given date onward,
     * re-freezing the correct rate for each. A day whose month is already paid
     * or locked is left exactly as it was.
     *
     * @return int number of days repriced
     */
    public function recalculateUnpaidAttendance(Employee $employee, string $fromDate): int
    {
        $rows = Attendance::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('date', '>=', $fromDate)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $attendanceService = app(AttendanceService::class);
        $repriced = 0;

        foreach ($rows as $row) {
            $date = $row->date->toDateString();
            $month = substr($date, 0, 7);

            if ($this->lock->isLocked((int) $row->company_id, $date)) {
                continue;
            }

            $paid = Payroll::query()->withoutGlobalScopes()
                ->where('employee_id', $employee->id)
                ->where('month', $month)
                ->where('status', PayrollStatus::Paid->value)
                ->exists();

            if ($paid) {
                continue;
            }

            $attendanceService->recalculateRow($row, $employee);
            $repriced++;
        }

        return $repriced;
    }

    /**
     * Point the employee's cached wage fields at whatever rate is active today,
     * so the employee form and list keep showing the current figure.
     */
    private function syncEmployeeToActiveRate(Employee $employee): void
    {
        $record = $this->rateForDate($employee->id, now()->toDateString());

        if ($record === null || $record->wage_type === null) {
            return;
        }

        $employee->wage_type = $record->wage_type;
        $rate = (string) $record->rate;

        // Clear the four rate columns, then set the one this type uses.
        $employee->setAttribute('wage_rate', null);
        $employee->setAttribute('daily_wage', null);
        $employee->setAttribute('base_salary', null);
        $employee->setAttribute('per_meter_rate', null);

        match ($record->wage_type) {
            WageType::Hourly => $employee->setAttribute('wage_rate', $rate),
            WageType::Daily => $employee->setAttribute('daily_wage', $rate),
            WageType::Monthly => $employee->setAttribute('base_salary', $rate),
            WageType::PerMeter => $employee->setAttribute('per_meter_rate', $rate),
        };

        $employee->saveQuietly(); // avoid re-triggering EmployeeService history
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value !== null ? (float) $value : null;
    }

    private function liveAmountFor(Employee $employee): ?float
    {
        $value = match ($employee->wage_type) {
            WageType::Hourly => $employee->getAttribute('wage_rate'),
            WageType::Daily => $employee->getAttribute('daily_wage'),
            WageType::Monthly => $employee->getAttribute('base_salary'),
            WageType::PerMeter => $employee->getAttribute('per_meter_rate'),
            default => null,
        };

        return $value !== null ? (float) $value : null;
    }

    private function hourlyFor(WageType $type, float $rate): ?float
    {
        return match ($type) {
            WageType::Hourly => $rate,
            WageType::Daily => round($rate / 8, 2),
            default => null, // monthly / per-meter: pay is not per attendance hour
        };
    }

    private function fallbackHourly(Employee $employee): ?float
    {
        $rate = $employee->getAttribute('wage_rate');
        $daily = $employee->getAttribute('daily_wage');

        return match ($employee->wage_type) {
            WageType::Hourly => $rate !== null ? (float) $rate : null,
            WageType::Daily => $daily !== null ? round((float) $daily / 8, 2) : null,
            default => $rate !== null ? (float) $rate : null,
        };
    }
}
