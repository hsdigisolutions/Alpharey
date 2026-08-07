<?php

namespace App\Http\Controllers;

use App\Enums\WageType;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Requests\Employees\UpdateEmployeeRequest;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeWageRate;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\UserColumnSetting;
use App\Services\Documents\DocumentStatus;
use App\Services\Employees\EmployeeQueryFilter;
use App\Services\Employees\EmployeeService;
use App\Services\Employees\WageRateService;
use App\Support\DocumentTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screens 05 (list) + 06 (detail). The list implements the Data
 * Management Standards (§10): sort whitelist, multi-filter, live search
 * (code/name + NIF via blind index), column visibility per user,
 * pagination 25/50/100. Every payload is permission-filtered server-side.
 */
class EmployeeController extends Controller
{
    private const SORTABLE = ['employee_code', 'full_name', 'department', 'designation', 'joining_date', 'created_at'];

    public function index(Request $request, DocumentStatus $status, EmployeeQueryFilter $filter): Response
    {
        Gate::authorize('employees.view');

        // Eager-load both relations the row shape reads (documents for the
        // compliance dot, company for its name) — without `company` the list
        // fires one query per employee (found by the Phase 9 N+1 guard).
        $query = $filter->apply($request)->with(['documents', 'company:id,name']);

        $sort = in_array($request->string('sort')->value(), self::SORTABLE, true)
            ? $request->string('sort')->value()
            : 'full_name';
        $dir = $request->string('dir')->value() === 'desc' ? 'desc' : 'asc';

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true)
            ? $request->integer('per_page')
            : 25;

        $canSeeWages = Gate::allows('payroll.view') || Gate::allows('employees.edit');

        $employees = $query->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'company' => $employee->company?->name,
                'department' => $employee->department,
                'designation' => $employee->designation,
                'city' => $employee->city,
                'mobile' => $employee->mobile,
                'wage_type' => $employee->wage_type?->value,
                // Salary figures only for roles that manage wages (server-side filter)
                'wage_rate' => $canSeeWages ? $employee->getAttribute('wage_rate') : null,
                'base_salary' => $canSeeWages ? $employee->getAttribute('base_salary') : null,
                'commission_percent' => $canSeeWages ? $employee->commission_percent : null,
                'active' => $employee->active,
                'doc_status' => $status->worst($employee->documents),
            ]);

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'filters' => $request->only(['search', 'status', 'department', 'designation', 'wage_type', 'sort', 'dir', 'per_page']),
            'filterOptions' => [
                'departments' => Employee::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
                'designations' => Employee::query()->whereNotNull('designation')->distinct()->orderBy('designation')->pluck('designation'),
                'wageTypes' => array_map(fn (WageType $type) => $type->value, WageType::cases()),
            ],
            'visibleColumns' => UserColumnSetting::for($request->user(), 'employees'),
            'canSeeWages' => $canSeeWages,
            'can' => [
                'create' => Gate::allows('employees.create'),
                'edit' => Gate::allows('employees.edit'),
                'delete' => Gate::allows('employees.delete'),
                'export' => Gate::allows('employees.export'),
            ],
        ]);
    }

    public function show(Request $request, Employee $employee, DocumentStatus $status, WageRateService $wageRates): Response
    {
        Gate::authorize('employees.view');

        $canSeeWages = Gate::allows('payroll.view') || Gate::allows('employees.edit');
        $canSeeBank = $canSeeWages;

        return Inertia::render('Employees/Detail', [
            'employee' => array_merge($employee->only([
                'id', 'employee_code', 'full_name', 'email', 'mobile', 'phone', 'city', 'address',
                'department', 'designation', 'team_leader_id', 'active', 'is_contracted',
                'default_check_in', 'default_check_out', 'commission_percent',
                'has_driving_license', 'has_company_vehicle', 'can_use_vehicles', 'notes',
            ]), [
                'joining_date' => $employee->joining_date?->toDateString(),
                'leaving_date' => $employee->leaving_date?->toDateString(),
                'company' => $employee->company?->name,
                'team_leader' => $employee->teamLeader?->full_name,
                'wage_type' => $employee->wage_type?->value,
                'payment_method' => $employee->payment_method?->value,
                // Sensitive fields: explicit, permission-gated exposure only
                'nif' => $employee->nif,
                'iban' => $canSeeBank ? $employee->iban : null,
                'bank_name' => $canSeeBank ? $employee->bank_name : null,
                'wage_rate' => $canSeeWages ? $employee->getAttribute('wage_rate') : null,
                'base_salary' => $canSeeWages ? $employee->getAttribute('base_salary') : null,
                'daily_wage' => $canSeeWages ? $employee->getAttribute('daily_wage') : null,
                'per_meter_rate' => $canSeeWages ? $employee->getAttribute('per_meter_rate') : null,
            ]),
            // Mobile PWA access (Worker PWA). Only whether a login exists and
            // which address it uses — never anything about the credential.
            'appAccess' => [
                'email' => $employee->user?->email,
                'active' => (bool) $employee->user?->active,
            ],
            'documents' => $this->documentsPayload($employee, $status),
            'documentSets' => DocumentTypes::employee(),
            'notes' => $employee->employeeNotes()->with('author:id,name')->orderByDesc('noted_at')->get()
                ->map(fn ($note) => [
                    'id' => $note->id,
                    'type' => $note->type,
                    'body' => $note->body,
                    'noted_at' => $note->noted_at->toDateTimeString(),
                    'author' => $note->author?->name,
                    'attachment_name' => $note->attachment_name,
                ]),
            'calls' => $employee->callLogs()->with('caller:id,name')->orderByDesc('called_at')->get()
                ->map(fn ($call) => [
                    'id' => $call->id,
                    'called_at' => $call->called_at->toDateTimeString(),
                    'called_by' => $call->caller?->name,
                    'remarks' => $call->remarks,
                    'follow_up_date' => $call->follow_up_date?->toDateString(),
                ]),
            // Nómina tab — pay data, so only for a wage viewer; empty otherwise.
            'payroll' => $canSeeWages ? $this->payrollRows($employee) : [],
            // Historial de Salario — the effective-dated wage timeline.
            'wageHistory' => $canSeeWages ? $this->wageHistoryRows($employee, $wageRates) : [],
            // Asistencia tab — per-employee month grid + summary (attendance.view gated).
            'attendanceTab' => Gate::allows('attendance.view')
                ? $this->attendanceTabPayload($employee, $request->string('att_month')->value() ?: now()->format('Y-m'), $canSeeWages)
                : null,
            'attendanceEditing' => Gate::allows('attendance.view') && $request->filled('att_edit')
                ? $this->attendanceEditingPayload($employee, (int) $request->integer('att_edit'), $canSeeWages)
                : null,
            'attendanceProjects' => Gate::allows('attendance.view') ? $this->attendanceProjects() : [],
            'attendanceEmployee' => Gate::allows('attendance.view') ? $this->attendanceEmployeePayload($employee, $canSeeWages) : null,
            'canManageAttendance' => Gate::allows('attendance.create'),
            'canSeeWages' => $canSeeWages,
            'can' => [
                'edit' => Gate::allows('employees.edit'),
                'delete' => Gate::allows('employees.delete'),
                'manageWages' => Gate::allows('employees.edit'),
                'upload' => Gate::allows('documents.upload'),
                'download' => Gate::allows('documents.download'),
                'deleteDocs' => Gate::allows('documents.delete'),
            ],
        ]);
    }

    /**
     * The employee's payroll history for the Nómina tab. Figures are encrypted
     * at rest and decrypted here; the caller only reaches this behind the wage
     * gate. The employee belongs to the acting company, so the tenant scope on
     * Payroll resolves to the right rows.
     *
     * @return list<array<string, mixed>>
     */
    private function payrollRows(Employee $employee): array
    {
        return Payroll::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('month')
            ->get()
            ->map(fn (Payroll $p): array => [
                'id' => $p->id,
                'month' => $p->month,
                'gross_pay' => (float) $p->getAttribute('gross_pay'),
                'net_amount' => (float) $p->getAttribute('net_amount'),
                'status' => $p->status->value,
                'paid_at' => $p->paid_at?->toDateString(),
            ])
            ->all();
    }

    /**
     * The effective-dated wage timeline for the Historial de Salario section.
     * Rates are encrypted at rest and decrypted here, behind the wage gate; the
     * record covering today is flagged as the current (active) rate.
     *
     * @return list<array<string, mixed>>
     */
    private function wageHistoryRows(Employee $employee, WageRateService $wageRates): array
    {
        $currentId = $wageRates->rateForDate($employee->id, now()->toDateString())?->id;

        return $wageRates->history($employee)
            ->map(fn (EmployeeWageRate $r): array => [
                'id' => $r->id,
                'wage_type' => $r->wage_type?->value,
                'rate' => (float) $r->rate,
                'effective_from' => $r->effective_from->toDateString(),
                'effective_to' => $r->effective_to?->toDateString(),
                'reason' => $r->reason,
                'is_current' => $r->id === $currentId,
            ])
            ->all();
    }

    /**
     * The Asistencia tab: this employee's month grid + monthly summary. A
     * deployed worker's host-logged days count too, so the query drops the
     * tenant scope and pins to the employee (their pay is home-company's).
     *
     * @return array<string, mixed>
     */
    private function attendanceTabPayload(Employee $employee, string $month, bool $canSeeWages): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $records = Attendance::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with('project:id,name')
            ->get();

        $grid = [];
        foreach ($records as $r) {
            $grid[(int) $r->date->format('j')] = [
                'id' => $r->id,
                'status' => $r->status->value,
                'day_type' => $r->day_type?->value,
                'hours' => (float) $r->hours_worked,
                'quantity' => $r->quantity !== null ? (float) $r->quantity : null,
                'project' => $r->project?->name,
                'total' => $canSeeWages ? (float) $r->total_amount : null,
            ];
        }

        $weekend = [];
        for ($d = 1; $d <= $start->daysInMonth; $d++) {
            $weekend[$d] = $start->copy()->day($d)->isWeekend();
        }

        // status is an enum cast, so compare ->value (the Phase 4 grid-summary bug).
        $worked = $records->filter(fn (Attendance $r) => in_array($r->status->value, ['present', 'late', 'early_leave'], true));

        return [
            'month' => $month,
            'days_in_month' => $start->daysInMonth,
            'weekend' => $weekend,
            'grid' => $grid,
            'summary' => [
                'present' => $worked->count(),
                'hours' => round((float) $records->sum(fn (Attendance $r) => (float) $r->hours_worked), 2),
                'overtime' => round((float) $records->sum(fn (Attendance $r) => (float) $r->overtime_hours), 2),
                'absences' => $records->filter(fn (Attendance $r) => $r->status->value === 'absent')->count(),
                'leaves' => $records->filter(fn (Attendance $r) => $r->status->value === 'leave')->count(),
                'total_wage' => $canSeeWages ? round((float) $records->sum(fn (Attendance $r) => (float) $r->total_amount), 2) : null,
            ],
        ];
    }

    /**
     * The edit payload for one of THIS employee's attendance days (cell click).
     *
     * @return array<string, mixed>|null
     */
    private function attendanceEditingPayload(Employee $employee, int $attendanceId, bool $canSeeWages): ?array
    {
        $a = Attendance::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->find($attendanceId);

        if ($a === null) {
            return null;
        }

        return [
            'id' => $a->id,
            'employee_id' => $a->employee_id,
            'project_id' => $a->project_id,
            'date' => $a->date->toDateString(),
            'mode' => $a->mode->value,
            'day_type' => $a->day_type?->value,
            'check_in' => $a->check_in,
            'check_out' => $a->check_out,
            'break_hours' => (float) $a->break_hours,
            'deduct_break' => $a->deduct_break,
            'hours_worked' => (float) $a->hours_worked,
            'quantity' => $a->quantity !== null ? (float) $a->quantity : null,
            'overtime_hours' => (float) $a->overtime_hours,
            'status' => $a->status->value,
            'total_amount' => $canSeeWages ? (float) $a->total_amount : null,
            'manual_wage_override' => $a->manual_wage_override,
            'is_paid' => $a->is_paid,
            'is_exception' => $a->is_exception,
            'exception_reason' => $a->exception_reason,
            'notes' => $a->notes,
        ];
    }

    /**
     * Projects for the attendance modal's dropdown.
     *
     * @return list<array<string, mixed>>
     */
    private function attendanceProjects(): array
    {
        return Project::query()->with('client:id,company_name')->orderBy('name')
            ->get(['id', 'name', 'client_id'])
            ->map(fn (Project $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'client_name' => $p->client?->company_name,
            ])->all();
    }

    /**
     * This employee shaped for the attendance modal (single-row dropdown), with
     * the wage bases the day-type preview needs when the viewer may see pay.
     *
     * @return array<string, mixed>
     */
    private function attendanceEmployeePayload(Employee $employee, bool $canSeeWages): array
    {
        $row = [
            'id' => $employee->id,
            'full_name' => $employee->full_name,
            'designation' => $employee->designation,
        ];

        if ($canSeeWages) {
            $daily = $employee->getAttribute('daily_wage');
            $hourly = $employee->getAttribute('wage_rate');
            $perMeter = $employee->getAttribute('per_meter_rate');
            $row['daily_rate'] = $daily !== null ? round((float) $daily, 2) : null;
            $row['per_meter_rate'] = $perMeter !== null ? round((float) $perMeter, 2) : null;
            $row['hourly_rate_raw'] = $hourly !== null ? round((float) $hourly, 2) : null;
            $row['hourly_rate'] = match ($employee->wage_type) {
                WageType::Hourly => $hourly !== null ? round((float) $hourly, 2) : null,
                WageType::Daily => $daily !== null ? round((float) $daily / 8, 2) : null,
                default => $hourly !== null ? round((float) $hourly, 2) : null,
            };
        }

        return $row;
    }

    public function store(StoreEmployeeRequest $request, EmployeeService $service): RedirectResponse
    {
        $service->create($request->validated());

        return back()->with('success', __('ui.employees.saved'));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $service->update($employee, $request->validated());

        return back()->with('success', __('ui.employees.saved'));
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('employees.delete');

        $employee->delete(); // soft delete — employees are never hard-deleted

        return redirect()->route('employees.index')->with('success', __('ui.employees.deleted'));
    }

    /**
     * Bulk activate/deactivate — the Phase 2 bulk actions.
     */
    public function bulkActive(Request $request): RedirectResponse
    {
        Gate::authorize('employees.edit');

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
            'active' => ['required', 'boolean'],
        ]);

        // The company scope confines the update to the caller's company rows
        Employee::query()->whereIn('id', $validated['ids'])->get()
            ->each(fn (Employee $employee) => $employee->update(['active' => $validated['active']]));

        return back()->with('success', __('ui.employees.saved'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentsPayload(Employee $employee, DocumentStatus $status): array
    {
        return $employee->documents()->where('is_current', true)->get()
            ->map(function ($document) use ($status): array {
                [$state, $daysLeft] = $status->of($document);

                return [
                    'id' => $document->id,
                    'category' => $document->category,
                    'type_key' => $document->type_key,
                    'name' => $document->name,
                    'original_name' => $document->original_name,
                    'has_file' => $document->file_path !== null,
                    'has_flag' => $document->has_flag,
                    'issue_date' => $document->issue_date?->toDateString(),
                    'expiry_date' => $document->expiry_date?->toDateString(),
                    'version' => $document->version,
                    'status' => $state,
                    'days_left' => $daysLeft,
                    'notes' => $document->notes,
                ];
            })->values()->all();
    }
}
