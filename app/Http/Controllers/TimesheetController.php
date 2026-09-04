<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\DayType;
use App\Exports\TimesheetExport;
use App\Exports\TimesheetProjectCalendarExport;
use App\Exports\TimesheetProjectDetailExport;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Services\Attendance\AttendanceService;
use App\Services\Audit\AuditLogger;
use App\Support\CompanyBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Timesheet — a weekly (or monthly) per-employee view of attendance: each day's
 * project, check-in/out, hours and day type, plus a period total and days
 * present. Read-only; admins pick any employee and navigate by week or month.
 */
class TimesheetController extends Controller
{
    use ResolvesCompanyContext;

    private const WORKED = [AttendanceStatus::Present->value, AttendanceStatus::Late->value, AttendanceStatus::EarlyLeave->value];

    /** Spanish weekday abbreviations, keyed by ISO weekday (1=Mon…7=Sun). */
    private const WEEKDAYS_ES = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];

    public function index(Request $request): Response
    {
        Gate::authorize('attendance.view');

        $this->contextCompanyId(); // ensures a company is selected (SA → Welcome)

        $view = $request->query('view') === 'project' ? 'project' : 'employee';
        $employees = Employee::query()->where('active', true)->orderBy('full_name')->get(['id', 'full_name']);
        $employeeId = $this->resolveEmployeeId($request, $employees->pluck('id')->all());
        [$mode, $start, $end] = $this->resolveRange($request);
        $projectId = is_numeric($request->query('project')) ? (int) $request->query('project') : null;

        if ($view === 'project') {
            // All employees who worked on the project in the period, summarised.
            $sheet = $projectId !== null
                ? $this->buildProjectTimesheet($projectId, $start, $end)
                : ['rows' => [], 'total_hours' => 0.0, 'workers' => 0];
        } else {
            $sheet = $employeeId !== null
                ? $this->buildTimesheet($employeeId, $start, $end, $projectId)
                : ['rows' => [], 'total_hours' => 0.0, 'days_present' => 0];
        }

        return Inertia::render('Timesheet/Index', [
            'employees' => $employees->map(fn (Employee $e): array => ['id' => $e->id, 'name' => $e->full_name])->all(),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Project $p): array => ['id' => $p->id, 'name' => $p->name])->all(),
            'filters' => [
                'view' => $view,
                'employee' => $employeeId,
                'mode' => $mode,
                'date' => $start->toDateString(),
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'project' => $projectId,
            ],
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'sheet' => $sheet,
            'can' => ['export' => Gate::allows('attendance.export')],
        ]);
    }

    /**
     * By-project view — every employee who worked on the project in the window,
     * with their days present + hours. Deployed-in workers count (scope dropped,
     * pinned to the project).
     *
     * @return array{rows: list<array<string, mixed>>, total_hours: float, workers: int}
     */
    private function buildProjectTimesheet(int $projectId, Carbon $start, Carbon $end): array
    {
        // NET worked hours (a full 08:00–17:00 day reads 8 h) for the acting
        // company's timesheet.
        $breakMinutes = app(AttendanceService::class)->breakDurationMinutes($this->contextCompanyId());

        $rows = Attendance::query()->withoutGlobalScope(CompanyScope::class)
            ->where('project_id', $projectId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with('employee:id,full_name,designation')
            ->get()
            ->groupBy('employee_id')
            ->map(function ($group) use ($breakMinutes) {
                /** @var Collection<int, Attendance> $group */
                $first = $group->first();
                $worked = $group->filter(fn (Attendance $a): bool => in_array($a->status->value, self::WORKED, true));

                // The specific worked days (the "which dates" detail) — ordered,
                // each carrying its day type + hours. Summary totals are summed
                // FROM these rounded per-day values so the detail reconciles to
                // the summary exactly (audit requirement).
                $days = $worked
                    ->sortBy(fn (Attendance $a): string => $a->date->toDateString())
                    ->map(fn (Attendance $a): array => [
                        'date' => $a->date->toDateString(),
                        'date_fmt' => $a->date->format('d/m/Y'),
                        'weekday' => self::WEEKDAYS_ES[$a->date->dayOfWeekIso] ?? '',
                        'day_type' => $a->day_type?->value,
                        'day_type_label' => $this->dayTypeLabel($a->day_type),
                        'hours' => $a->displayHoursNet($breakMinutes),
                    ])
                    ->values()
                    ->all();

                return [
                    'employee_id' => $first?->employee_id,
                    'employee' => $first?->employee?->full_name,
                    'designation' => $first?->employee?->designation,
                    'days_present' => count($days),
                    'hours' => round(array_sum(array_column($days, 'hours')), 2),
                    'days' => $days,
                ];
            })
            ->sortByDesc('hours')
            ->values()
            ->all();

        // Calendar-grid axis + per-day aggregates. Built from the SAME per-day
        // rows above (each day's `hours` is displayHoursNet), so the grid view
        // reconciles to the table view and to the summary to the cent — never a
        // second hours calculation. daily_present = workers on the project that
        // day; daily_hours = the sum of their net hours that day.
        $days = [];
        $dailyPresent = [];
        $dailyHours = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $days[] = ['day' => (int) $cursor->day, 'date' => $key, 'weekend' => $cursor->isWeekend()];
            $dailyPresent[$key] = 0;
            $dailyHours[$key] = 0.0;
        }
        foreach ($rows as $row) {
            foreach ($row['days'] as $day) {
                $key = $day['date'];
                if (! array_key_exists($key, $dailyPresent)) {
                    continue;
                }
                $dailyPresent[$key]++;
                $dailyHours[$key] = round($dailyHours[$key] + (float) $day['hours'], 2);
            }
        }

        $totalHours = round(array_sum(array_map(fn (array $r): float => (float) $r['hours'], $rows)), 2);
        $totalDays = array_sum(array_map(fn (array $r): int => (int) $r['days_present'], $rows));

        return [
            'rows' => $rows,
            'total_hours' => $totalHours,
            'total_days' => $totalDays,
            'workers' => count($rows),
            // Everything the calendar/spreadsheet grid needs; the table view
            // ignores it and keeps using rows + totals as before.
            'calendar' => [
                'days' => $days,
                'daily_present' => $dailyPresent,
                'daily_hours' => $dailyHours,
                'grand_total_days' => $totalDays,
                'grand_total_hours' => $totalHours,
            ],
        ];
    }

    /** Spanish day-type label for the timesheet detail. */
    private function dayTypeLabel(?DayType $type): string
    {
        return match ($type) {
            DayType::Full => 'Jornada completa',
            DayType::Half => 'Media jornada',
            DayType::Hourly => 'Por horas',
            DayType::PerMeter => 'Por metros',
            default => '—',
        };
    }

    /**
     * By-project CALENDAR export — the workers × days matrix from the on-screen
     * grid, in PDF (landscape) or Excel. Both are built from the same $sheet the
     * grid renders, so they reconcile to the table view to the cent.
     *
     * @param  array<string, mixed>  $sheet
     */
    private function exportProjectCalendar(Project $project, Carbon $start, Carbon $end, array $sheet, string $format): BinaryFileResponse|HttpResponse
    {
        $calendar = $this->buildCalendarExport($sheet);

        if ($format === 'pdf') {
            return Pdf::loadView('exports.timesheet-project-calendar-pdf', [
                'project' => $project->name,
                'start' => $start->format('d/m/Y'),
                'end' => $end->format('d/m/Y'),
                'calendar' => $calendar,
                'logo' => CompanyBranding::currentLogo(),
            ])->setPaper('a4', 'landscape')->download('parte-horas-calendario-'.$project->id.'.pdf');
        }

        // Excel: one "Calendario" sheet — a header row of day columns, one row per
        // worker with the day mark per day, then the workers/día and horas/día
        // footer rows. The right-hand Días / Horas columns carry the per-worker
        // totals so the sheet stays analytically complete.
        $headings = array_merge(
            ['Trabajador', 'Designación'],
            array_map(fn (array $d): string => $d['label'], $calendar['day_cols']),
            ['Días', 'Horas'],
        );

        $rows = [];
        foreach ($calendar['rows'] as $r) {
            $line = [(string) $r['employee'], (string) $r['designation']];
            foreach ($calendar['day_cols'] as $d) {
                $line[] = (string) ($r['marks'][$d['date']] ?? '');
            }
            $line[] = (int) $r['days_present'];
            $line[] = (float) $r['hours'];
            $rows[] = $line;
        }

        // Two footer rows, aligned to the same columns.
        $workersRow = ['Trabajadores/día', ''];
        $hoursRow = ['Horas/día', ''];
        foreach ($calendar['day_cols'] as $d) {
            $workersRow[] = (int) ($calendar['daily_present'][$d['date']] ?? 0);
            $hoursRow[] = (float) ($calendar['daily_hours'][$d['date']] ?? 0);
        }
        $workersRow[] = (int) $calendar['grand_days'];
        $workersRow[] = '';
        $hoursRow[] = '';
        $hoursRow[] = (float) $calendar['grand_hours'];
        $rows[] = $workersRow;
        $rows[] = $hoursRow;

        return Excel::download(
            new TimesheetProjectCalendarExport($headings, $rows),
            'parte-horas-calendario-'.$project->id.'.xlsx',
        );
    }

    /**
     * Reshape the project $sheet into a calendar-matrix payload for export: the
     * day-column axis (label + weekend flag), one row per worker carrying a
     * date→mark map (F / H / hours, mirroring the grid tile), the per-day
     * aggregates and the grand totals.
     *
     * @param  array<string, mixed>  $sheet
     * @return array{day_cols: list<array{date: string, day: int, weekday: string, label: string, weekend: bool}>, rows: list<array{employee: string, designation: string, days_present: int, hours: float, marks: array<string, string>}>, daily_present: array<string, int>, daily_hours: array<string, float>, grand_days: int, grand_hours: float}
     */
    private function buildCalendarExport(array $sheet): array
    {
        /** @var array<string, mixed> $cal */
        $cal = $sheet['calendar'] ?? ['days' => [], 'daily_present' => [], 'daily_hours' => [], 'grand_total_days' => 0, 'grand_total_hours' => 0.0];

        $dayCols = array_map(function (array $d): array {
            $weekday = self::WEEKDAYS_ES[Carbon::parse((string) $d['date'])->dayOfWeekIso] ?? '';

            return [
                'date' => (string) $d['date'],
                'day' => (int) $d['day'],
                'weekday' => $weekday,
                'label' => $d['day'].' '.$weekday,
                'weekend' => (bool) $d['weekend'],
            ];
        }, $cal['days']);

        $rows = array_map(function (array $r): array {
            $marks = [];
            foreach ($r['days'] as $day) {
                $marks[(string) $day['date']] = $this->calendarMark($day['day_type'] ?? null, (float) $day['hours']);
            }

            return [
                'employee' => (string) ($r['employee'] ?? ''),
                'designation' => (string) ($r['designation'] ?? '—'),
                'days_present' => (int) $r['days_present'],
                'hours' => (float) $r['hours'],
                'marks' => $marks,
            ];
        }, $sheet['rows']);

        return [
            'day_cols' => $dayCols,
            'rows' => $rows,
            'daily_present' => $cal['daily_present'],
            'daily_hours' => $cal['daily_hours'],
            'grand_days' => (int) $cal['grand_total_days'],
            'grand_hours' => (float) $cal['grand_total_hours'],
        ];
    }

    /**
     * The calendar cell mark, mirroring the grid tile: full → F, half → H,
     * hourly / per-meter → the net hours (blank when zero).
     */
    private function calendarMark(?string $dayType, float $hours): string
    {
        return match ($dayType) {
            DayType::Full->value => 'F',
            DayType::Half->value => 'H',
            default => $this->fmtCalendarHours($hours),
        };
    }

    /** Compact hours for a calendar cell: integer when whole, else one decimal. */
    private function fmtCalendarHours(float $hours): string
    {
        if ($hours <= 0.0) {
            return '';
        }

        return $hours == (float) (int) $hours ? (string) (int) $hours : number_format(round($hours, 1), 1);
    }

    public function export(Request $request, AuditLogger $audit): BinaryFileResponse|HttpResponse
    {
        Gate::authorize('attendance.export');
        $this->contextCompanyId();

        [, $start, $end] = $this->resolveRange($request);
        $projectId = is_numeric($request->query('project')) ? (int) $request->query('project') : null;
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'excel';

        // By-project view — export whichever layout is on screen: the Calendar
        // grid (workers × days matrix) or the summary + detail Table.
        if ($request->query('view') === 'project') {
            abort_if($projectId === null, 404);
            $project = Project::query()->findOrFail($projectId);
            $sheet = $this->buildProjectTimesheet($projectId, $start, $end);
            $display = $request->query('display') === 'calendar' ? 'calendar' : 'table';
            $audit->log('exported', $project, null, null, 'Timesheet project '.strtoupper($display).' '.strtoupper($format), 'attendance');

            if ($display === 'calendar') {
                return $this->exportProjectCalendar($project, $start, $end, $sheet, $format);
            }

            if ($format === 'pdf') {
                return Pdf::loadView('exports.timesheet-project-pdf', [
                    'project' => $project->name,
                    'start' => $start->format('d/m/Y'),
                    'end' => $end->format('d/m/Y'),
                    'sheet' => $sheet,
                    'logo' => CompanyBranding::currentLogo(),
                ])->download('parte-horas-'.$project->id.'.pdf');
            }

            // Excel: two sheets — Resumen (per-worker totals) + Detalle (per
            // worker, per day). Built from the same $sheet so they reconcile.
            $summaryRows = array_map(fn (array $r): array => [
                (string) ($r['employee'] ?? ''),
                (string) ($r['designation'] ?? '—'),
                (int) $r['days_present'],
                (float) $r['hours'],
            ], $sheet['rows']);

            $detailRows = [];
            foreach ($sheet['rows'] as $r) {
                foreach ($r['days'] as $d) {
                    $detailRows[] = [
                        (string) ($r['employee'] ?? ''),
                        (string) $d['date_fmt'],
                        (string) $d['weekday'],
                        (string) $d['day_type_label'],
                        (float) $d['hours'],
                    ];
                }
            }

            return Excel::download(
                new TimesheetProjectDetailExport($summaryRows, $detailRows),
                'parte-horas-'.$project->id.'.xlsx',
            );
        }

        $employeeId = $this->resolveEmployeeId($request, Employee::query()->pluck('id')->all());
        abort_if($employeeId === null, 404);
        $employee = Employee::query()->findOrFail($employeeId);
        $sheet = $this->buildTimesheet($employeeId, $start, $end, $projectId);

        $audit->log('exported', $employee, null, null, 'Timesheet '.strtoupper($format), 'attendance');

        if ($format === 'pdf') {
            return Pdf::loadView('exports.timesheet-pdf', [
                'employee' => $employee->full_name,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'sheet' => $sheet,
                'logo' => CompanyBranding::currentLogo(),
            ])->download('timesheet.pdf');
        }

        return Excel::download(new TimesheetExport($sheet['rows'], $employee->full_name), 'timesheet.xlsx');
    }

    /**
     * @param  list<int>  $allowed
     */
    private function resolveEmployeeId(Request $request, array $allowed): ?int
    {
        $requested = is_numeric($request->query('employee')) ? (int) $request->query('employee') : null;
        if ($requested !== null && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $allowed[0] ?? null;
    }

    /**
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    private function resolveRange(Request $request): array
    {
        $mode = (string) $request->query('mode', 'week');
        $anchor = $request->filled('date')
            ? Carbon::parse((string) $request->query('date'))
            : Carbon::now();

        if ($mode === 'month') {
            return ['month', $anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()];
        }

        // Custom range: explicit from/to (falls back to a week around the anchor).
        if ($mode === 'custom') {
            $from = $request->filled('from') ? Carbon::parse((string) $request->query('from')) : $anchor->copy()->startOfWeek(Carbon::MONDAY);
            $to = $request->filled('to') ? Carbon::parse((string) $request->query('to')) : $anchor->copy()->endOfWeek(Carbon::SUNDAY);
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }

            return ['custom', $from->startOfDay(), $to->startOfDay()];
        }

        // Monday-first week (default).
        return ['week', $anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total_hours: float, days_present: int}
     */
    private function buildTimesheet(int $employeeId, Carbon $start, Carbon $end, ?int $projectId): array
    {
        // NET worked hours (a full 08:00–17:00 day reads 8 h) for the acting
        // company's timesheet.
        $breakMinutes = app(AttendanceService::class)->breakDurationMinutes($this->contextCompanyId());

        // Pinned to the employee, scope dropped — a deployed worker's host rows
        // count too (same rule as the employee attendance tab).
        $byDate = Attendance::query()->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with('project:id,name')
            ->get()
            ->keyBy(fn (Attendance $a): string => $a->date->toDateString());

        $rows = [];
        $totalHours = 0.0;
        $daysPresent = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $r = $byDate->get($cursor->toDateString());

            // A project filter hides days worked on a different project.
            if ($projectId !== null && ($r === null || $r->project_id !== $projectId)) {
                $r = null;
            }

            $worked = $r !== null && in_array($r->status->value, self::WORKED, true);
            $hours = $r !== null ? $r->displayHoursNet($breakMinutes) : 0.0;
            if ($worked) {
                $daysPresent++;
                $totalHours += $hours;
            }

            $rows[] = [
                'date' => $cursor->toDateString(),
                'weekday' => (int) $cursor->dayOfWeekIso, // 1=Mon…7=Sun
                'project' => $r?->project?->name,
                'check_in' => $r?->check_in,
                'check_out' => $r?->check_out,
                'hours' => $r !== null ? $hours : null,
                'day_type' => $r?->day_type?->value,
                'status' => $r?->status->value ?? 'absent',
            ];

            $cursor->addDay();
        }

        return ['rows' => $rows, 'total_hours' => round($totalHours, 2), 'days_present' => $daysPresent];
    }
}
