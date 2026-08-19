<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Exports\TimesheetExport;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Services\Audit\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
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

    public function index(Request $request): Response
    {
        Gate::authorize('attendance.view');

        $this->contextCompanyId(); // ensures a company is selected (SA → Welcome)

        $employees = Employee::query()->where('active', true)->orderBy('full_name')->get(['id', 'full_name']);
        $employeeId = $this->resolveEmployeeId($request, $employees->pluck('id')->all());
        [$mode, $start, $end] = $this->resolveRange($request);
        $projectId = is_numeric($request->query('project')) ? (int) $request->query('project') : null;

        $sheet = $employeeId !== null
            ? $this->buildTimesheet($employeeId, $start, $end, $projectId)
            : ['rows' => [], 'total_hours' => 0.0, 'days_present' => 0];

        return Inertia::render('Timesheet/Index', [
            'employees' => $employees->map(fn (Employee $e): array => ['id' => $e->id, 'name' => $e->full_name])->all(),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Project $p): array => ['id' => $p->id, 'name' => $p->name])->all(),
            'filters' => [
                'employee' => $employeeId,
                'mode' => $mode,
                'date' => $start->toDateString(),
                'project' => $projectId,
            ],
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'sheet' => $sheet,
            'can' => ['export' => Gate::allows('attendance.export')],
        ]);
    }

    public function export(Request $request, AuditLogger $audit): BinaryFileResponse|HttpResponse
    {
        Gate::authorize('attendance.export');
        $this->contextCompanyId();

        $employeeId = $this->resolveEmployeeId($request, Employee::query()->pluck('id')->all());
        abort_if($employeeId === null, 404);
        [, $start, $end] = $this->resolveRange($request);
        $projectId = is_numeric($request->query('project')) ? (int) $request->query('project') : null;

        $employee = Employee::query()->findOrFail($employeeId);
        $sheet = $this->buildTimesheet($employeeId, $start, $end, $projectId);
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'excel';

        $audit->log('exported', $employee, null, null, 'Timesheet '.strtoupper($format), 'attendance');

        if ($format === 'pdf') {
            return Pdf::loadView('exports.timesheet-pdf', [
                'employee' => $employee->full_name,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'sheet' => $sheet,
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
        $mode = $request->query('mode') === 'month' ? 'month' : 'week';
        $anchor = $request->filled('date')
            ? Carbon::parse((string) $request->query('date'))
            : Carbon::now();

        if ($mode === 'month') {
            return ['month', $anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()];
        }

        // Monday-first week.
        return ['week', $anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total_hours: float, days_present: int}
     */
    private function buildTimesheet(int $employeeId, Carbon $start, Carbon $end, ?int $projectId): array
    {
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
            $hours = $r !== null ? (float) $r->hours_worked : 0.0;
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
