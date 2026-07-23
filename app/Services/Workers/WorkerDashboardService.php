<?php

namespace App\Services\Workers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Carbon;

/**
 * The worker's own month, for the PWA dashboard.
 *
 * Everything is one employee's data, read with the tenant scope dropped
 * (a worker is not browsing a company, they ARE one employee) but reached
 * only through their own id. It never touches another worker's rows.
 *
 * The figures mirror exactly what the attendance grid and payroll already
 * compute for these days — this is a read-only view of the same rows, not a
 * second source of truth.
 */
class WorkerDashboardService
{
    /** Statuses that count as an attended day (same set as PayrollService). */
    private const WORKED = ['present', 'late', 'early_leave'];

    /**
     * @return array{
     *     month: string,
     *     label: string,
     *     days_in_month: int,
     *     present: int,
     *     absent: int,
     *     hours: float,
     *     earned: float,
     *     calendar: list<array{day:int, weekday:int, status:string}>
     * }
     */
    public function forMonth(Employee $employee, ?string $month = null): array
    {
        $start = $month !== null
            ? Carbon::createFromFormat('Y-m', $month)->startOfMonth()
            : Carbon::now()->startOfMonth();

        $end = $start->copy()->endOfMonth();
        $today = Carbon::now()->startOfDay();

        // date-string => status, for the month, this employee only.
        $rows = Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['date', 'status', 'hours_worked', 'total_amount']);

        $byDate = $rows->keyBy(fn (Attendance $r): string => $r->date->toDateString());

        $present = 0;
        $absent = 0;
        $hours = 0.0;
        $earned = 0.0;
        $calendar = [];

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $row = $byDate->get($cursor->toDateString());
            $status = $this->cellStatus($row, $cursor, $today);

            if ($status === 'present') {
                $present++;
            } elseif ($status === 'absent') {
                $absent++;
            }

            $calendar[] = [
                'day' => $cursor->day,
                // 0 = Monday … 6 = Sunday, so the grid can offset the first row
                // and grey the weekend regardless of locale.
                'weekday' => (int) $cursor->dayOfWeekIso - 1,
                'status' => $status,
                // The one day the worker can actually act on — highlighted, and
                // it is the only date any punch ever writes to (server-enforced).
                'is_today' => $cursor->isSameDay($today),
            ];

            $cursor->addDay();
        }

        // Hours and earnings are the real attendance figures — the same numbers
        // the payroll run reads. For an hourly/daily worker this is their exact
        // pay so far; a monthly-salaried worker's per-day total is 0 here, so
        // the amount reflects attendance, not the fixed salary (payroll adds
        // that separately). Worth surfacing to the client for confirmation.
        foreach ($rows as $row) {
            $hours += (float) $row->hours_worked;
            $earned += (float) $row->total_amount;
        }

        return [
            'month' => $start->format('Y-m'),
            'label' => $start->locale('es')->isoFormat('MMMM YYYY'),
            'days_in_month' => $end->day,
            'present' => $present,
            'absent' => $absent,
            'hours' => round($hours, 2),
            'earned' => round($earned, 2),
            'calendar' => $calendar,
        ];
    }

    /**
     * The dot a day shows:
     *  - present : green — an attended day
     *  - absent  : red — a recorded absence
     *  - leave   : an approved leave day (blue, shown distinct from absent)
     *  - none    : grey — a weekend, a future date, or a day with no record yet
     */
    private function cellStatus(?Attendance $row, Carbon $day, Carbon $today): string
    {
        if ($row !== null) {
            if (in_array($row->status->value, self::WORKED, true)) {
                return 'present';
            }

            return $row->status->value; // absent | leave
        }

        // No record. A future date or a weekend is simply grey, not "absent" —
        // a worker has not failed to show up for a day that has not happened,
        // or for a Sunday.
        if ($day->isWeekend() || $day->gt($today)) {
            return 'none';
        }

        // A past weekday with no record: still grey, not auto-marked absent.
        // Absence is a positive act (the worker reports it, or a clerk enters
        // it); the app must not accuse someone of an absence they never had.
        return 'none';
    }
}
