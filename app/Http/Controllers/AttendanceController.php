<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Http\Requests\Attendance\StoreAttendanceRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRequest;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Project;
use App\Services\Attendance\AttendanceService;
use App\Support\CurrentCompany;
use App\Support\PeriodLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 11 — Attendance calendar grid. Rows = employees, columns = days of
 * the selected month; each cell carries status + hours + project. Company-
 * owned (the global scope confines every query to the active company).
 */
class AttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('attendance.view');

        $month = $this->resolveMonth($request);
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        // Own active employees…
        $employees = Employee::query()->where('active', true)->orderBy('full_name')
            ->get(['id', 'full_name', 'designation'])
            ->map(fn (Employee $e): array => [
                'id' => $e->id,
                'full_name' => $e->full_name,
                'designation' => $e->designation,
                'deployed' => false,
                'home_company' => null,
            ]);

        // …plus employees from OTHER companies deployed INTO this one whose
        // deployment overlaps the shown month (Phase 5 — they log hours against
        // the host project and appear with a "Desplegado" badge).
        $employees = $employees
            ->concat($this->deployedInEmployees($start, $end))
            ->values();

        // Attendance rows for exactly the employees on the grid (own + deployed).
        $employeeIds = $employees->pluck('id')->all();

        $records = Attendance::query()
            ->withoutGlobalScopes()
            ->where('company_id', app(CurrentCompany::class)->id())
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with('project:id,name')
            ->get();

        // grid[employee_id][day] = cell
        $grid = [];
        foreach ($records as $record) {
            $day = (int) $record->date->format('j');
            $grid[$record->employee_id][$day] = [
                'id' => $record->id,
                'status' => $record->status->value,
                'hours' => (float) $record->hours_worked,
                'project' => $record->project?->name,
            ];
        }

        // Monthly summary per employee
        $summary = $records->groupBy('employee_id')->map(function ($rows) {
            // status is an AttendanceStatus enum cast — compare on ->value
            $countStatus = fn (array $statuses): int => $rows
                ->filter(fn ($r) => in_array($r->status->value, $statuses, true))->count();

            return [
                'days_present' => $countStatus(['present', 'late', 'early_leave']),
                'hours' => round((float) $rows->sum(fn ($r) => (float) $r->hours_worked), 2),
                'overtime' => round((float) $rows->sum(fn ($r) => (float) $r->overtime_hours), 2),
                'absences' => $countStatus(['absent']),
                'leave' => $countStatus(['leave']),
                'total_wage' => round((float) $rows->sum(fn ($r) => (float) $r->total_amount), 2),
            ];
        });

        // When ?edit=ID is present the frontend requests it as a partial
        // reload (Inertia `only: ['editing']`) to populate the edit modal.
        $editing = null;
        if ($request->filled('edit')) {
            $record = Attendance::query()->find($request->integer('edit'));
            $editing = $record !== null ? $this->payload($record) : null;
        }

        return Inertia::render('Attendance/Index', [
            'month' => $month->format('Y-m'),
            'daysInMonth' => $end->day,
            'employees' => $employees,
            'grid' => $grid,
            'summary' => $summary,
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'editing' => $editing,
            'can' => [
                'create' => Gate::allows('attendance.create'),
                'edit' => Gate::allows('attendance.edit'),
                'delete' => Gate::allows('attendance.delete'),
                'export' => Gate::allows('attendance.export'),
            ],
        ]);
    }

    public function store(StoreAttendanceRequest $request, AttendanceService $service): RedirectResponse
    {
        $service->create($request->validated());

        return back()->with('success', __('ui.attendance.saved'));
    }

    public function update(UpdateAttendanceRequest $request, Attendance $attendance, AttendanceService $service): RedirectResponse
    {
        $service->update($attendance, $request->validated());

        return back()->with('success', __('ui.attendance.saved'));
    }

    public function destroy(Attendance $attendance, PeriodLock $lock): RedirectResponse
    {
        Gate::authorize('attendance.delete');

        // A closed month is closed for deletes too, not just edits.
        $lock->assertOpen($attendance->company_id, $attendance->date);

        $attendance->delete();

        return back()->with('success', __('ui.attendance.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Attendance $attendance): array
    {
        $canSeeWage = Gate::allows('payroll.view') || Gate::allows('employees.edit');

        return [
            'id' => $attendance->id,
            'employee_id' => $attendance->employee_id,
            'project_id' => $attendance->project_id,
            'date' => $attendance->date->toDateString(),
            'mode' => $attendance->mode->value,
            'check_in' => $attendance->check_in,
            'check_out' => $attendance->check_out,
            'break_hours' => (float) $attendance->break_hours,
            'deduct_break' => $attendance->deduct_break,
            'hours_worked' => (float) $attendance->hours_worked,
            'overtime_hours' => (float) $attendance->overtime_hours,
            'status' => $attendance->status->value,
            'total_amount' => $canSeeWage ? (float) $attendance->total_amount : null,
            'manual_wage_override' => $attendance->manual_wage_override,
            'is_paid' => $attendance->is_paid,
            'is_exception' => $attendance->is_exception,
            'exception_reason' => $attendance->exception_reason,
            'notes' => $attendance->notes,
        ];
    }

    /**
     * Employees from other companies deployed INTO the active company whose
     * deployment window overlaps the shown month. Crosses the tenant scope by
     * design (this IS the cross-company feature); flagged so the grid renders a
     * home-company badge.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function deployedInEmployees(Carbon $start, Carbon $end): Collection
    {
        $hostCompanyId = app(CurrentCompany::class)->id();

        if ($hostCompanyId === null) {
            return collect();
        }

        return EmployeeDeployment::query()
            ->where('host_company_id', $hostCompanyId)
            ->where('status', DeploymentStatus::Active->value)
            ->where('deployment_start', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('deployment_end')
                ->orWhere('deployment_end', '>=', $start->toDateString()))
            ->with(['employee:id,full_name,designation', 'homeCompany:id,name'])
            ->get()
            ->filter(fn (EmployeeDeployment $d): bool => $d->employee !== null)
            ->map(fn (EmployeeDeployment $d): array => $this->deployedRow($d))
            ->values();
    }

    /**
     * Grid-row shape for a deployed employee (home-company badge fields).
     *
     * @return array<string, mixed>
     */
    private function deployedRow(EmployeeDeployment $deployment): array
    {
        return [
            'id' => $deployment->employee?->id,
            'full_name' => $deployment->employee?->full_name,
            'designation' => $deployment->employee?->designation,
            'deployed' => true,
            'home_company' => $deployment->homeCompany?->name,
        ];
    }

    private function resolveMonth(Request $request): Carbon
    {
        $raw = $request->string('month')->value();

        if ($raw !== '' && preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m', $raw)->startOfMonth();
        }

        return now()->startOfMonth();
    }
}
