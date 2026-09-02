<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\DayType;
use App\Exports\TimesheetExport;
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

        return [
            'rows' => $rows,
            'total_hours' => round(array_sum(array_map(fn (array $r): float => (float) $r['hours'], $rows)), 2),
            'total_days' => array_sum(array_map(fn (array $r): int => (int) $r['days_present'], $rows)),
            'workers' => count($rows),
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

    public function export(Request $request, AuditLogger $audit): BinaryFileResponse|HttpResponse
    {
        Gate::authorize('attendance.export');
        $this->contextCompanyId();

        [, $start, $end] = $this->resolveRange($request);
        $projectId = is_numeric($request->query('project')) ? (int) $request->query('project') : null;
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'excel';

        // By-project view — export the per-employee summary for the project.
        if ($request->query('view') === 'project') {
            abort_if($projectId === null, 404);
            $project = Project::query()->findOrFail($projectId);
            $sheet = $this->buildProjectTimesheet($projectId, $start, $end);
            $audit->log('exported', $project, null, null, 'Timesheet project '.strtoupper($format), 'attendance');

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
