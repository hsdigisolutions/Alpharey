<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Http\Requests\Attendance\StoreAttendanceRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRequest;
use App\Models\Attendance;
use App\Models\AttendanceVoiceNote;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Project;
use App\Services\Attendance\AttendanceService;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentCompany;
use App\Support\PeriodLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        // Which of these attendance rows carry a worker voice/text note — a
        // single query keyed by attendance_id so the grid can show a mic marker
        // without an N+1. Scoped to the same company as the rows above.
        $notedAttendanceIds = AttendanceVoiceNote::query()
            ->whereIn('attendance_id', $records->pluck('id'))
            ->pluck('attendance_id')
            ->flip();

        // grid[employee_id][day] = cell
        $grid = [];
        foreach ($records as $record) {
            $day = (int) $record->date->format('j');
            $grid[$record->employee_id][$day] = [
                'id' => $record->id,
                'status' => $record->status->value,
                'hours' => (float) $record->hours_worked,
                'project' => $record->project?->name,
                'has_voice_note' => $notedAttendanceIds->has($record->id),
            ];
        }

        // Monthly summary per employee. The wage total is gated exactly like
        // the cell payload below — hours are attendance data, money is pay data.
        $canSeeWage = Gate::allows('payroll.view') || Gate::allows('employees.edit');

        $summary = $records->groupBy('employee_id')->map(function ($rows) use ($canSeeWage) {
            // status is an AttendanceStatus enum cast — compare on ->value
            $countStatus = fn (array $statuses): int => $rows
                ->filter(fn ($r) => in_array($r->status->value, $statuses, true))->count();

            return [
                'days_present' => $countStatus(['present', 'late', 'early_leave']),
                'hours' => round((float) $rows->sum(fn ($r) => (float) $r->hours_worked), 2),
                'overtime' => round((float) $rows->sum(fn ($r) => (float) $r->overtime_hours), 2),
                'absences' => $countStatus(['absent']),
                'leave' => $countStatus(['leave']),
                'total_wage' => $canSeeWage
                    ? round((float) $rows->sum(fn ($r) => (float) $r->total_amount), 2)
                    : null,
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
     * The check-in selfie for one attendance row.
     *
     * A private file, so it is served ONLY through here: permission-checked
     * (viewing attendance is the right to see it), tenant-scoped by route
     * binding, and audited on every view — a photo of a person is exactly the
     * kind of access that must leave a trail. Never a public URL.
     */
    public function selfie(Attendance $attendance, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('attendance.view');

        $path = $attendance->check_in_photo_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        $audit->log('viewed', $attendance, null, null, 'Check-in selfie', 'attendance');

        return Storage::disk('local')->response($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Attendance $attendance): array
    {
        $canSeeWage = Gate::allows('payroll.view') || Gate::allows('employees.edit');

        // The worker's check-out note (Feature 1). The audio itself is streamed
        // through the gated, audited download route — never inlined here.
        $voiceNote = AttendanceVoiceNote::query()
            ->where('attendance_id', $attendance->id)
            ->first();

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
            // Worker PWA capture (Phase E). Present only on phone punches; the
            // map link is built client-side from the coordinates, and the photo
            // is fetched through the gated download route, never a public URL.
            'worker' => $attendance->source === 'worker' ? [
                'source' => $attendance->source,
                'note' => $attendance->worker_note,
                'location_denied' => $attendance->location_denied,
                'check_in_at' => $attendance->check_in_at?->toDateTimeString(),
                'check_out_at' => $attendance->check_out_at?->toDateTimeString(),
                'check_in' => $this->coords($attendance->check_in_lat, $attendance->check_in_lng, $attendance->check_in_accuracy),
                'check_out' => $this->coords($attendance->check_out_lat, $attendance->check_out_lng, $attendance->check_out_accuracy),
                'has_photo' => $attendance->check_in_photo_path !== null,
            ] : null,
            // Voice/text note captured at check-out. Independent of the worker
            // capture block above (a note can exist without GPS/selfie data).
            'voice_note' => $voiceNote !== null ? [
                'id' => $voiceNote->id,
                'text_note' => $voiceNote->text_note,
                'has_audio' => $voiceNote->audio_path !== null,
                'duration_seconds' => $voiceNote->duration_seconds,
            ] : null,
        ];
    }

    /**
     * A coordinate triple for the client, or null when there is no fix.
     * The columns are decimal casts, so their values arrive as numeric strings.
     *
     * @return array{lat: float, lng: float, accuracy: float|null}|null
     */
    private function coords(?string $lat, ?string $lng, float|string|null $accuracy): ?array
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'accuracy' => $accuracy !== null ? (float) $accuracy : null,
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
