<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\WageType;
use App\Http\Requests\Attendance\StoreAttendanceRequest;
use App\Http\Requests\Attendance\StoreBulkAttendanceRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRequest;
use App\Models\Attendance;
use App\Models\AttendanceVoiceNote;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Project;
use App\Models\ProjectEmployeeRate;
use App\Models\Scopes\CompanyScope;
use App\Services\Attendance\AttendanceService;
use App\Services\Audit\AuditLogger;
use App\Support\AttendanceAbsence;
use App\Support\CurrentCompany;
use App\Support\Geo;
use App\Support\PeriodLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        // Own active employees… (joining_date kept for the live-absence sweep).
        $ownEmployees = Employee::query()->where('active', true)->orderBy('full_name')
            ->get(['id', 'full_name', 'designation', 'joining_date', 'active', 'active_since', 'transferred_at']);

        $employees = $ownEmployees->map(fn (Employee $e): array => [
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
            ->with('project:id,name,latitude,longitude,geofence_radius')
            ->get();

        // Off-site threshold for this company — the grid classifies each cell's
        // check-in distance into a traffic-light band with it (computed once).
        $offSiteThreshold = app(AttendanceService::class)->offSiteAlertDistance(app(CurrentCompany::class)->id());

        // Which of these attendance rows carry a worker note — one query, keyed
        // by attendance_id so the grid can mark it without an N+1. We also track
        // whether the note has AUDIO, so the cell can show a mic for a voice note
        // and a plain note glyph for a text-only note (not everything is a mic).
        $noteHasAudio = AttendanceVoiceNote::query()
            ->whereIn('attendance_id', $records->pluck('id'))
            ->get(['attendance_id', 'audio_path'])
            ->mapWithKeys(fn (AttendanceVoiceNote $n): array => [$n->attendance_id => $n->audio_path !== null]);

        // grid[employee_id][day] = cell
        $grid = [];
        foreach ($records as $record) {
            $day = (int) $record->date->format('j');
            $grid[$record->employee_id][$day] = [
                'id' => $record->id,
                'status' => $record->status->value,
                'day_type' => $record->day_type?->value,
                'is_weekend' => (bool) $record->is_weekend,
                'is_auto' => (bool) $record->is_auto_generated,
                // Day-type auto-detection badges: 'auto' while the system's grade
                // stands, 'edit' once an admin has overridden a detected day.
                'is_auto_detected' => (bool) $record->is_auto_detected,
                'is_overridden' => ! $record->is_auto_detected && $record->auto_day_type !== null,
                'hours' => (float) $record->hours_worked,
                'quantity' => $record->quantity !== null ? (float) $record->quantity : null,
                'project' => $record->project?->name,
                'has_voice_note' => $noteHasAudio->has($record->id),
                'voice_note_has_audio' => (bool) $noteHasAudio->get($record->id, false),
                'location_mismatch' => (bool) $record->location_mismatch,
                // Distance from the project site + its traffic-light band, for the
                // small dot on the cell. Null when unverified (no fix / no coords).
                'distance' => $record->distance_from_project !== null ? (float) $record->distance_from_project : null,
                'distance_band' => $this->distanceBand($record, $offSiteThreshold),
            ];
        }

        // Live absences: an own employee's unrecorded past weekday (on/after
        // joining) is shown as an auto-absence immediately — the same rule the
        // worker PWA uses (AttendanceAbsence), so both views agree without
        // waiting for the nightly attendance:auto-absent sweep. Deployed-in
        // workers are excluded (their HOME company owns their absences).
        $today = now()->startOfDay();
        $workingDays = app(AttendanceService::class)
            ->workingDays(app(CurrentCompany::class)->id() ?? 0);
        $virtualAbsences = [];
        foreach ($ownEmployees as $emp) {
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $day = (int) $cursor->format('j');
                if (! isset($grid[$emp->id][$day])
                    && AttendanceAbsence::isUnrecordedAbsence($cursor, $today, $emp->joining_date, $emp->active, $emp->active_since, $emp->transferred_at, $workingDays)) {
                    $grid[$emp->id][$day] = [
                        'id' => null, // no real row — clicking it opens "new entry"
                        'status' => 'absent',
                        'day_type' => null,
                        'is_weekend' => false,
                        'is_auto' => true, // lighter shade, like a nightly auto-absence
                        'is_auto_detected' => false,
                        'is_overridden' => false,
                        'hours' => 0.0,
                        'quantity' => null,
                        'project' => null,
                        'has_voice_note' => false,
                        'location_mismatch' => false,
                        'distance' => null,
                        'distance_band' => null,
                    ];
                    $virtualAbsences[$emp->id] = ($virtualAbsences[$emp->id] ?? 0) + 1;
                }
                $cursor->addDay();
            }
        }

        // When the acting user can see wage data, compute a per-employee hourly
        // rate from the frozen wage fields so the modal can show an auto-fill
        // preview. wage_rate/daily_wage are $hidden so we access via getAttribute.
        $canSeeWage = Gate::allows('payroll.view') || Gate::allows('employees.edit');

        if ($canSeeWage) {
            $wageEmployees = Employee::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->whereIn('id', $employeeIds)
                ->get(['id', 'wage_type', 'wage_rate', 'daily_wage', 'per_meter_rate'])
                ->keyBy('id');

            // All four rate bases the day-type preview needs. hourly_rate keeps
            // the derived value the older preview used (daily → daily/8).
            $employees = $employees->map(function (array $e) use ($wageEmployees): array {
                $wEmp = $wageEmployees->get($e['id']);
                if ($wEmp !== null) {
                    $rate = $wEmp->getAttribute('wage_rate');
                    $daily = $wEmp->getAttribute('daily_wage');
                    $perMeter = $wEmp->getAttribute('per_meter_rate');
                    $e['daily_rate'] = $daily !== null ? round((float) $daily, 2) : null;
                    $e['per_meter_rate'] = $perMeter !== null ? round((float) $perMeter, 2) : null;
                    $e['hourly_rate'] = match ($wEmp->wage_type) {
                        WageType::Hourly => $rate !== null ? round((float) $rate, 2) : null,
                        WageType::Daily => $daily !== null ? round((float) $daily / 8, 2) : null,
                        default => $rate !== null ? round((float) $rate, 2) : null,
                    };
                    // For a daily worker the "hourly" clock preview isn't the pay
                    // base; expose the real hourly rate column too when present.
                    $e['hourly_rate_raw'] = $rate !== null ? round((float) $rate, 2) : null;
                }

                return $e;
            });
        }

        // Project→employee assignment map for the "show project workers first"
        // feature in the entry modals. Only employee ids, no wage data here.
        $projectAssignments = ProjectEmployeeRate::query()
            ->get(['project_id', 'employee_id'])
            ->groupBy('project_id')
            ->map(fn ($rows) => $rows->pluck('employee_id')->values()->all())
            ->all();

        // Monthly summary per employee. The wage total is gated exactly like
        // the cell payload below — hours are attendance data, money is pay data.

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

        // Fold the live absences into the "Absences" column so the summary and
        // the grid agree. An employee with ONLY live absences gets a fresh row.
        foreach ($virtualAbsences as $empId => $count) {
            if ($summary->has($empId)) {
                $row = $summary->get($empId);
                $row['absences'] += $count;
                $summary->put($empId, $row);
            } else {
                $summary->put($empId, [
                    'days_present' => 0,
                    'hours' => 0.0,
                    'overtime' => 0.0,
                    'absences' => $count,
                    'leave' => 0,
                    'total_wage' => $canSeeWage ? 0.0 : null,
                ]);
            }
        }

        // When ?edit=ID is present the frontend requests it as a partial
        // reload (Inertia `only: ['editing']`) to populate the edit modal.
        $editing = null;
        if ($request->filled('edit')) {
            $record = Attendance::query()->find($request->integer('edit'));
            $editing = $record !== null ? $this->payload($record) : null;
        }

        // Load projects with their client name for the searchable dropdown.
        // `projects` (all) feeds the roster filter; `formProjects` (active only)
        // feeds the create/edit attendance modals (Change 4).
        $projectRows = Project::query()->with('client:id,company_name')->orderBy('name')
            ->get(['id', 'name', 'client_id', 'status'])
            ->map(fn (Project $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'client_name' => $p->client?->company_name,
                'selectable' => in_array($p->status, [ProjectStatus::Active, ProjectStatus::InProgress], true),
            ]);
        $projects = $projectRows->map(fn (array $p): array => Arr::except($p, ['selectable']))->all();
        $formProjects = $projectRows->where('selectable', true)
            ->map(fn (array $p): array => Arr::except($p, ['selectable']))->values()->all();

        return Inertia::render('Attendance/Index', [
            'month' => $month->format('Y-m'),
            'daysInMonth' => $end->day,
            'employees' => $employees,
            'grid' => $grid,
            'summary' => $summary,
            'projects' => $projects,
            'formProjects' => $formProjects,
            'projectAssignments' => $projectAssignments,
            // Feature 1 — the day's roster for a selected project (partial reload
            // via ?project + ?panel_date). Null unless a project is chosen.
            'projectPanel' => $this->projectPanel($request, $offSiteThreshold),
            'canSeeWage' => $canSeeWage,
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

    public function storeBulk(StoreBulkAttendanceRequest $request, AttendanceService $service): RedirectResponse
    {
        $data = $request->validated();
        $employeeIds = $data['employee_ids'];
        $date = $data['date'];

        // Skip any employee that already has an attendance row on this date so
        // we never violate the (employee_id, date) unique index.
        $existing = Attendance::query()
            ->withoutGlobalScopes()
            ->where('company_id', app(CurrentCompany::class)->id())
            ->whereIn('employee_id', $employeeIds)
            ->where('date', $date)
            ->pluck('employee_id')
            ->flip()
            ->all();

        $created = 0;
        $skipped = count($existing);

        foreach ($employeeIds as $employeeId) {
            if (isset($existing[$employeeId])) {
                continue;
            }

            $service->create(array_merge($data, ['employee_id' => $employeeId]));
            $created++;
        }

        $msg = strtr(__('ui.attendance.bulk_created'), [':count' => $created]);
        if ($skipped > 0) {
            $msg .= ' — '.strtr(__('ui.attendance.bulk_skipped'), [':count' => $skipped]);
        }

        return back()->with('success', $msg);
    }

    public function update(UpdateAttendanceRequest $request, Attendance $attendance, AttendanceService $service): RedirectResponse
    {
        $service->update($attendance, $request->validated());

        return back()->with('success', __('ui.attendance.saved'));
    }

    public function destroy(Attendance $attendance, PeriodLock $lock): RedirectResponse
    {
        Gate::authorize('attendance.delete');

        // A closed month is closed for deletes too, not just edits — and a month
        // already PAID for this worker is closed even before it is locked.
        $lock->assertOpen($attendance->company_id, $attendance->date);
        app(AttendanceService::class)->assertMonthNotPaid((int) $attendance->employee_id, $attendance->date);

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
     * The worker's proof-of-work attachment (site photo / document) captured at
     * check-out. Same gated + audited private-file rules as the selfie.
     */
    public function checkOutAttachment(Attendance $attendance, AuditLogger $audit, int $which = 1): StreamedResponse
    {
        Gate::authorize('attendance.view');

        // which = 1 (the required photo) | 2 | 3 (optional extras).
        [$path, $name] = match ($which) {
            2 => [$attendance->check_out_attachment_2_path, $attendance->check_out_attachment_2_name],
            3 => [$attendance->check_out_attachment_3_path, $attendance->check_out_attachment_3_name],
            default => [$attendance->check_out_attachment_path, $attendance->check_out_attachment_name],
        };

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        $audit->log('viewed', $attendance, null, null, "Check-out attachment {$which}", 'attendance');

        // Served INLINE so an image renders in an <img>/tab and a PDF opens in
        // the tab; a doc/docx the browser can't display simply downloads.
        return Storage::disk('local')->response($path, $name);
    }

    /**
     * The present check-out photos (1 required + up to 2 optional) as
     * {which, name, is_image} rows for the admin modal.
     *
     * @return list<array{which: int, name: string|null, is_image: bool}>
     */
    private function checkoutPhotos(Attendance $attendance): array
    {
        $slots = [
            1 => [$attendance->check_out_attachment_path, $attendance->check_out_attachment_name],
            2 => [$attendance->check_out_attachment_2_path, $attendance->check_out_attachment_2_name],
            3 => [$attendance->check_out_attachment_3_path, $attendance->check_out_attachment_3_name],
        ];

        $photos = [];
        foreach ($slots as $which => [$path, $name]) {
            if ($path === null) {
                continue;
            }
            $photos[] = [
                'which' => $which,
                'name' => $name,
                'is_image' => $name !== null && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $name) === 1,
            ];
        }

        return $photos;
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
            'day_type' => $attendance->day_type?->value,
            'check_in' => $attendance->check_in,
            'check_out' => $attendance->check_out,
            'break_hours' => (float) $attendance->break_hours,
            'deduct_break' => $attendance->deduct_break,
            'hours_worked' => (float) $attendance->hours_worked,
            'quantity' => $attendance->quantity !== null ? (float) $attendance->quantity : null,
            'overtime_hours' => (float) $attendance->overtime_hours,
            'status' => $attendance->status->value,
            'total_amount' => $canSeeWage ? (float) $attendance->total_amount : null,
            'manual_wage_override' => $attendance->manual_wage_override,
            'override_reason' => $attendance->override_reason,
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
                'location_mismatch' => $attendance->location_mismatch,
                'check_in_at' => $attendance->check_in_at?->toDateTimeString(),
                'check_out_at' => $attendance->check_out_at?->toDateTimeString(),
                'check_in' => $this->coords($attendance->check_in_lat, $attendance->check_in_lng, $attendance->check_in_accuracy),
                'check_out' => $this->coords($attendance->check_out_lat, $attendance->check_out_lng, $attendance->check_out_accuracy),
                // Distance from the project site at check-in + its traffic-light
                // band; null when it could not be verified (no fix / no coords).
                'project_name' => $attendance->project?->name,
                'distance_from_project' => $attendance->distance_from_project !== null ? (float) $attendance->distance_from_project : null,
                'distance_band' => $this->distanceBand($attendance, app(AttendanceService::class)->offSiteAlertDistance($attendance->company_id)),
                'has_photo' => $attendance->check_in_photo_path !== null,
                // Proof-of-work file captured at check-out (site photo / doc).
                // is_image lets the modal render a photo inline vs a doc download.
                'has_checkout_attachment' => $attendance->check_out_attachment_path !== null,
                'checkout_attachment_name' => $attendance->check_out_attachment_name,
                'checkout_attachment_is_image' => $attendance->check_out_attachment_name !== null
                    && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $attendance->check_out_attachment_name) === 1,
                // All present check-out photos (1 required + up to 2 optional),
                // each fetched through the gated download route by its `which`.
                'checkout_photos' => $this->checkoutPhotos($attendance),
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
     * The traffic-light band for a row's check-in distance from its project —
     * on_site / near_site / off_site — or null when unverified (no stored
     * distance or the project has no coordinates). Grades against the project's
     * own geofence radius and the company's off-site threshold.
     */
    /**
     * Feature 1 — the day's roster for a selected project: every ASSIGNED worker
     * (project rate rows + active deployments into the project) with their
     * attendance for that date, present AND absent, plus a present-of-assigned
     * tally. Null unless a project is selected.
     *
     * @return array{project: array{id: int, name: string}, date: string, rows: list<array<string, mixed>>, present: int, assigned: int}|null
     */
    private function projectPanel(Request $request, int $offSiteThreshold): ?array
    {
        $projectId = (int) $request->query('project', 0);
        if ($projectId <= 0) {
            return null;
        }

        $project = Project::query()->where('id', $projectId)->first(['id', 'name', 'latitude', 'longitude', 'geofence_radius']);
        if ($project === null) {
            return null;
        }

        $date = $request->filled('panel_date')
            ? Carbon::parse((string) $request->query('panel_date'))->toDateString()
            : now()->toDateString();

        // Assigned = project rate rows + workers actively deployed into the project.
        $assignedIds = ProjectEmployeeRate::query()->where('project_id', $projectId)->pluck('employee_id')
            ->merge(EmployeeDeployment::query()->where('project_id', $projectId)
                ->where('status', DeploymentStatus::Active)->pluck('employee_id'))
            ->filter()->unique()->values();

        $employees = Employee::query()->withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', $assignedIds)->orderBy('full_name')
            ->get(['id', 'full_name', 'designation']);

        $records = Attendance::query()->withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->where('date', $date)
            ->whereIn('employee_id', $assignedIds)
            ->with('project:id,name,latitude,longitude,geofence_radius')
            ->get()->keyBy('employee_id');

        $worked = ['present', 'late', 'early_leave'];
        $present = 0;

        $rows = $employees->map(function (Employee $e) use ($records, $worked, &$present, $offSiteThreshold): array {
            $r = $records->get($e->id);
            $status = 'absent';
            if ($r !== null) {
                if (in_array($r->status->value, $worked, true)) {
                    $status = $r->check_out_at === null ? 'working' : 'present';
                    $present++;
                } else {
                    $status = $r->status->value;
                }
            }

            $isWorked = $r !== null && in_array($r->status->value, $worked, true);

            return [
                'employee' => $e->full_name,
                'designation' => $e->designation,
                'check_in' => $r?->check_in_at?->format('H:i'),
                'check_out' => $r?->check_out_at?->format('H:i'),
                'hours' => $isWorked ? (float) $r->hours_worked : null,
                'status' => $status,
                'distance' => $r !== null && $r->distance_from_project !== null ? (float) $r->distance_from_project : null,
                'distance_band' => $r !== null ? $this->distanceBand($r, $offSiteThreshold) : null,
            ];
        })->values()->all();

        return [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'date' => $date,
            'rows' => $rows,
            'present' => $present,
            'assigned' => $employees->count(),
        ];
    }

    private function distanceBand(Attendance $record, int $offSiteThreshold): ?string
    {
        if ($record->distance_from_project === null || $record->project === null) {
            return null;
        }

        return Geo::band((float) $record->distance_from_project, $record->project->geofence_radius, $offSiteThreshold);
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
