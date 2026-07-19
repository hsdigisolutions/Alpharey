<?php

namespace App\Services\Leave;

use App\Enums\AttendanceMode;
use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\WageType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\Scopes\CompanyScope;
use App\Support\PeriodLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Screen 22 — the leave request lifecycle, and the two things it touches
 * beyond its own table.
 *
 * 1. BALANCES. A pending request holds its days against the balance
 *    immediately, so two requests that each fit the balance cannot both be
 *    approved when together they do not. Approval moves those days pending →
 *    used; rejection and cancellation give them back.
 *
 * 2. ATTENDANCE (and therefore PAYROLL). Approving leave writes the days into
 *    the attendance grid as AttendanceStatus::Leave — the blue cells. Payroll
 *    reads attendance, so this is also how leave reaches pay, without the
 *    payroll engine needing to know leave exists:
 *
 *      - unpaid leave, and paid leave for monthly/per-meter workers, book 0
 *        hours and 0 pay. A monthly salary already covers the day; paying it
 *        again through attendance would pay it twice.
 *      - paid leave for daily/hourly workers is priced from the wage snapshot
 *        frozen at write time, so it lands in the days_amount / hours_amount
 *        line exactly as a worked day would.
 *
 *    Both cases keep total_amount equal to hours × rate, which matters: the
 *    payroll engine splits every attendance row as `overtime = total − base`,
 *    so a row carrying pay without hours would silently surface as overtime.
 *
 * Known gap, deliberately not guessed at: unpaid leave does NOT reduce a
 * MONTHLY worker's salary, because the pro-rata divisor (30 days vs the
 * calendar month) is a real Spanish nómina choice that DECISIONS.md does not
 * record. Until the client confirms it, a clerk handles it through the
 * payroll screen's other_deductions field.
 */
class LeaveService
{
    /**
     * A standard working day. Matches AttendanceService::resolveHourlyRate(),
     * which derives an hourly rate from a daily wage over the same 8 hours.
     */
    private const STANDARD_DAY_HOURS = 8.0;

    public function __construct(private readonly PeriodLock $lock) {}

    /**
     * File a request. The days are held against the balance from this moment.
     *
     * @param  array<string, mixed>  $data
     */
    public function request(array $data): Leave
    {
        return DB::transaction(function () use ($data): Leave {
            $leave = new Leave($data);
            $leave->status = LeaveStatus::Pending;
            $leave->save();

            $this->balanceFor($leave)->increment('pending', (float) $leave->total_days);

            return $leave;
        });
    }

    /**
     * Approve, and book the days into the attendance grid.
     *
     * @throws ValidationException when the month is closed, or a day already
     *                             has attendance recorded against it
     */
    public function approve(Leave $leave, ?string $notes = null): Leave
    {
        $this->assertPending($leave);

        return DB::transaction(function () use ($leave, $notes): Leave {
            // Attendance is about to be written across the whole span, so every
            // month it touches has to be open — not just the first one.
            foreach ($this->monthsSpanned($leave) as $month) {
                $this->lock->assertOpen($leave->company_id, $month, 'start_date');
            }

            $this->assertNoAttendanceConflict($leave);

            $leave->status = LeaveStatus::Approved;
            $leave->reviewed_by = Auth::id();
            $leave->reviewed_at = now();
            $leave->review_notes = $notes;
            $leave->save();

            $balance = $this->balanceFor($leave);
            $balance->decrement('pending', (float) $leave->total_days);
            $balance->increment('used', (float) $leave->total_days);

            $this->writeAttendance($leave);

            return $leave;
        });
    }

    public function reject(Leave $leave, ?string $notes = null): Leave
    {
        $this->assertPending($leave);

        return DB::transaction(function () use ($leave, $notes): Leave {
            $leave->status = LeaveStatus::Rejected;
            $leave->reviewed_by = Auth::id();
            $leave->reviewed_at = now();
            $leave->review_notes = $notes;
            $leave->save();

            $this->balanceFor($leave)->decrement('pending', (float) $leave->total_days);

            return $leave;
        });
    }

    /**
     * Cancel a request — including one already approved, which withdraws the
     * attendance rows it booked. A cancelled leave must leave no trace in the
     * grid, or payroll would keep paying for days nobody took.
     */
    public function cancel(Leave $leave, ?string $notes = null): Leave
    {
        if (in_array($leave->status, [LeaveStatus::Cancelled, LeaveStatus::Rejected], true)) {
            throw ValidationException::withMessages([
                'status' => __('ui.leave.not_cancellable'),
            ]);
        }

        return DB::transaction(function () use ($leave, $notes): Leave {
            $wasApproved = $leave->status === LeaveStatus::Approved;

            if ($wasApproved) {
                foreach ($this->monthsSpanned($leave) as $month) {
                    $this->lock->assertOpen($leave->company_id, $month, 'start_date');
                }
            }

            $balance = $this->balanceFor($leave);

            if ($wasApproved) {
                $balance->decrement('used', (float) $leave->total_days);
                $this->removeAttendance($leave);
            } else {
                $balance->decrement('pending', (float) $leave->total_days);
            }

            $leave->status = LeaveStatus::Cancelled;
            $leave->reviewed_by = Auth::id();
            $leave->reviewed_at = now();
            $leave->review_notes = $notes ?? $leave->review_notes;
            $leave->save();

            return $leave;
        });
    }

    /**
     * Screen 22 "Adjust balance". Allocation and carry-over are the manager's
     * to set; used/pending are derived from requests and are not touched here.
     */
    public function adjustBalance(LeaveBalance $balance, float $allocated, float $carriedOver): LeaveBalance
    {
        $balance->allocated = (string) $allocated;
        $balance->carried_over = (string) $carriedOver;
        $balance->save();

        return $balance;
    }

    /**
     * The balance row this leave draws from, created on demand at the
     * category's default allocation for the year the leave starts in.
     */
    public function balanceFor(Leave $leave): LeaveBalance
    {
        $year = (int) $leave->start_date->format('Y');

        $balance = LeaveBalance::query()
            ->where('employee_id', $leave->employee_id)
            ->where('leave_category_id', $leave->leave_category_id)
            ->where('year', $year)
            ->first();

        if ($balance !== null) {
            return $balance;
        }

        $balance = new LeaveBalance([
            'employee_id' => $leave->employee_id,
            'leave_category_id' => $leave->leave_category_id,
            'year' => $year,
            'allocated' => (string) ($leave->category->default_allocation ?? '0'),
        ]);
        $balance->company_id = $leave->company_id;
        $balance->save();

        return $balance;
    }

    /**
     * Book the approved days as attendance. Weekends are skipped: a leave cell
     * on a Sunday is noise, not information.
     *
     * NOTE: there is no public-holiday calendar in the schema, so a Spanish
     * national/regional holiday inside a leave span still books a cell. That
     * costs nothing in pay (see the pricing rules above) but is worth a
     * holidays table later.
     */
    private function writeAttendance(Leave $leave): void
    {
        // Tenant scope only: approving leave for a soft-deleted employee must
        // fail rather than write attendance for someone no longer employed.
        $employee = Employee::query()->withoutGlobalScope(CompanyScope::class)->findOrFail($leave->employee_id);
        $paid = (bool) ($leave->category->is_paid ?? true);

        foreach ($this->workingDays($leave) as $day) {
            $hours = $this->payableHours($employee, $paid);

            $attendance = new Attendance([
                'employee_id' => $leave->employee_id,
                'date' => $day->toDateString(),
                'mode' => AttendanceMode::ProjectBased,
                'status' => AttendanceStatus::Leave,
                'hours_worked' => (string) $hours,
                'overtime_hours' => '0',
                'notes' => $leave->category->name ?? null,
                // The pricing below is deliberate, not a default to recompute.
                'manual_wage_override' => true,
            ]);

            $attendance->company_id = $leave->company_id;
            $attendance->wage_type_snapshot = $employee->wage_type;

            $rate = $employee->getAttribute('wage_rate');
            $attendance->wage_rate_snapshot = $rate !== null ? (float) $rate : null;

            $hourly = $this->hourlyRateFor($employee);
            $attendance->hourly_rate_snapshot = $hourly;

            // total MUST equal hours x rate: payroll reads any excess as overtime.
            $attendance->total_amount = (string) round($hours * (float) ($hourly ?? 0), 2);
            $attendance->save();
        }
    }

    /**
     * Withdraw the cells a cancelled leave booked.
     *
     * Deleting by status + date range is safe because approve() refuses when
     * ANY attendance already exists in the span: every Leave-status row in
     * there was therefore written by this request. Worked days are never
     * touched — they could not have coexisted with the approval in the first
     * place.
     */
    private function removeAttendance(Leave $leave): void
    {
        Attendance::query()->withoutGlobalScopes()
            ->where('employee_id', $leave->employee_id)
            ->where('status', AttendanceStatus::Leave->value)
            ->whereBetween('date', [
                $leave->start_date->toDateString(),
                $leave->end_date->toDateString(),
            ])
            ->delete();
    }

    /**
     * Hours to book for a leave day. Zero means "the day is covered elsewhere",
     * not "unpaid" — see the class docblock.
     */
    private function payableHours(Employee $employee, bool $paid): float
    {
        if (! $paid) {
            return 0.0;
        }

        return match ($employee->wage_type) {
            WageType::Daily, WageType::Hourly => self::STANDARD_DAY_HOURS,
            // Monthly salary and per-meter earnings already cover the day.
            default => 0.0,
        };
    }

    private function hourlyRateFor(Employee $employee): ?float
    {
        $rate = $employee->getAttribute('wage_rate');
        $daily = $employee->getAttribute('daily_wage');

        return match ($employee->wage_type) {
            WageType::Hourly => $rate !== null ? (float) $rate : null,
            WageType::Daily => $daily !== null ? round((float) $daily / self::STANDARD_DAY_HOURS, 2) : null,
            default => $rate !== null ? (float) $rate : null,
        };
    }

    /**
     * Weekdays covered by the request.
     *
     * @return list<Carbon>
     */
    private function workingDays(Leave $leave): array
    {
        $days = [];
        $cursor = $leave->start_date->copy();

        while ($cursor->lte($leave->end_date)) {
            if ($cursor->isWeekday()) {
                $days[] = $cursor->copy();
            }

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * Refuse to approve over a day that already has attendance: a worker
     * cannot be both on site and on leave, and the grid enforces one row per
     * employee per day anyway. Naming the dates is what makes this fixable.
     *
     * @throws ValidationException
     */
    private function assertNoAttendanceConflict(Leave $leave): void
    {
        $existing = Attendance::query()->withoutGlobalScopes()
            ->where('employee_id', $leave->employee_id)
            ->whereBetween('date', [
                $leave->start_date->toDateString(),
                $leave->end_date->toDateString(),
            ])
            ->pluck('date');

        if ($existing->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'start_date' => __('ui.leave.attendance_conflict', [
                'dates' => $existing->map(fn ($d) => Carbon::parse($d)->format('d/m/Y'))->join(', '),
            ]),
        ]);
    }

    /**
     * First day of every month the request touches — a span can cross one.
     *
     * @return list<string>
     */
    private function monthsSpanned(Leave $leave): array
    {
        $months = [];
        $cursor = $leave->start_date->copy()->startOfMonth();
        $last = $leave->end_date->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $months[] = $cursor->toDateString();
            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * @throws ValidationException
     */
    private function assertPending(Leave $leave): void
    {
        if ($leave->status !== LeaveStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => __('ui.leave.already_reviewed'),
            ]);
        }
    }
}
