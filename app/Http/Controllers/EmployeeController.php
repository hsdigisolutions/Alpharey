<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Enums\EquipmentIssueStatus;
use App\Enums\WageType;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Requests\Employees\TransferEmployeeRequest;
use App\Http\Requests\Employees\UpdateEmployeeRequest;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCompanyHistory;
use App\Models\EmployeeDeployment;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EmployeeWageRate;
use App\Models\EquipmentIncident;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Models\UserColumnSetting;
use App\Models\WorkerConsent;
use App\Services\Attendance\AttendanceService;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentStatus;
use App\Services\Employees\EmployeeQueryFilter;
use App\Services\Employees\EmployeeService;
use App\Services\Employees\EmployeeTransferService;
use App\Services\Employees\WageRateService;
use App\Services\Inventory\PpeComplianceService;
use App\Services\Workers\WorkerConsentService;
use App\Support\AttendanceAbsence;
use App\Support\CompanyBranding;
use App\Support\CurrentCompany;
use App\Support\DocumentPanelPayload;
use App\Support\DocumentTypes;
use App\Support\WorkerPrivacyNotice;
use Barryvdh\DomPDF\Facade\Pdf;
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
        $actingCompanyId = app(CurrentCompany::class)->id();

        // Resolved once, reused for both the list filter and the form dropdown.
        $departmentOptions = $this->departmentOptions();

        $paginator = $query->orderBy($sort, $dir)->paginate($perPage)->withQueryString();

        // Active OUTBOUND deployments for the employees on this page (one query,
        // no N+1) → employee_id ⇒ host company name, for the "Desplegado a X"
        // badge. Deployments are cross-company (no tenant scope), so this reads
        // them directly; home_company_id pins it to the acting company's own.
        $deployedMap = EmployeeDeployment::query()
            ->whereIn('employee_id', collect($paginator->items())->pluck('id'))
            ->where('status', DeploymentStatus::Active->value)
            ->when($actingCompanyId !== null, fn ($q) => $q->where('home_company_id', $actingCompanyId))
            ->with('hostCompany:id,name')
            ->get()
            ->keyBy('employee_id');

        $employees = $paginator
            ->through(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'company' => $employee->company?->name,
                'department' => $employee->department,
                'department_id' => $employee->department_id,
                'designation' => $employee->designation,
                'city' => $employee->city,
                'mobile' => $employee->mobile,
                'wage_type' => $employee->wage_type?->value,
                // Active outbound deployment → "Desplegado a {host company}".
                'deployed_to' => $deployedMap->get($employee->id)?->hostCompany?->name,
                // Salary figures only for roles that manage wages (server-side filter).
                // The Tarifa column shows the rate matching the worker's OWN wage
                // type — reading the hourly column for every type showed 0/blank
                // for daily, monthly and per-meter workers.
                'wage_rate' => $canSeeWages ? $employee->displayRate() : null,
                'base_salary' => $canSeeWages ? $employee->getAttribute('base_salary') : null,
                'commission_percent' => $canSeeWages ? $employee->commission_percent : null,
                'active' => $employee->active,
                // A row surfaced by the 'transferred' filter lives at another
                // company now — flag it so the list badges "Transferred to {company}"
                // (its `company` above is that new company) and opens read-only.
                'transferred_away' => $actingCompanyId !== null && (int) $employee->company_id !== $actingCompanyId,
                'status' => $employee->status(),
                'doc_status' => $status->worst($employee->documents),
            ]);

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'filters' => (object) $request->only(['search', 'status', 'department', 'designation', 'wage_type', 'sort', 'dir', 'per_page']),
            'filterOptions' => [
                // Departments now come from the company catalogue (Settings), not
                // free-text employee values — active departments only.
                'departments' => $departmentOptions,
                'designations' => Employee::query()->whereNotNull('designation')->distinct()->orderBy('designation')->pluck('designation'),
                'wageTypes' => array_map(fn (WageType $type) => $type->value, WageType::cases()),
            ],
            'visibleColumns' => UserColumnSetting::for($request->user(), 'employees'),
            'canSeeWages' => $canSeeWages,
            // Summary cards (server-computed, company-scoped, excludes soft-deleted).
            // Unaffected by the search/other filters — they are the company totals
            // and double as the status filter (click a card → filter by status).
            // One aggregate query (not three) to stay within the perf-guard budget.
            'stats' => $this->employeeStats(),
            // The trade-type catalogue for the create/edit form's dropdown.
            'designationOptions' => ProjectDesignationRateController::optionsFor(
                app(CurrentCompany::class)->id(),
            ),
            // The department catalogue for the create/edit form's dropdown.
            'departmentOptions' => $departmentOptions,
            'can' => [
                'create' => Gate::allows('employees.create'),
                'edit' => Gate::allows('employees.edit'),
                'delete' => Gate::allows('employees.delete'),
                'export' => Gate::allows('employees.export'),
            ],
        ]);
    }

    /**
     * Active departments for the acting company (Settings catalogue) — the
     * {id, name} source for the employee form dropdown and the list filter.
     *
     * @return list<array{id: int, name: string}>
     */
    private function departmentOptions(): array
    {
        return Department::query()->where('active', true)->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $d): array => ['id' => $d->id, 'name' => $d->name])
            ->all();
    }

    /**
     * Company-scoped employee totals for the summary cards, in ONE query
     * (soft-deleted excluded by the model). CASE/COALESCE keep it portable
     * across MySQL and the SQLite test DB.
     *
     * @return array{total: int, active: int, inactive: int}
     */
    private function employeeStats(): array
    {
        // Aliases must NOT collide with model attributes — aliasing as `active`
        // would apply the model's boolean cast and turn the SUM into true/1.
        $row = Employee::query()
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END), 0) as active_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN active = 0 THEN 1 ELSE 0 END), 0) as inactive_count')
            ->first();

        return [
            'total' => (int) ($row->total_count ?? 0),
            'active' => (int) ($row->active_count ?? 0),
            'inactive' => (int) ($row->inactive_count ?? 0),
        ];
    }

    public function show(Request $request, int $employeeId, DocumentStatus $status, WageRateService $wageRates, DocumentPanelPayload $panel): Response
    {
        Gate::authorize('employees.view');

        $actingCompanyId = app(CurrentCompany::class)->id();
        $isSuperAdmin = $request->user()?->isSuperAdmin() ?? false;

        // Resolve WITHOUT the tenant scope so the OLD company can open a worker
        // who has since transferred away (their record's company_id is the new
        // company now). SoftDeletes still applies, so pre-redesign orphan
        // records stay hidden.
        $employee = Employee::query()->withoutGlobalScope(CompanyScope::class)->findOrFail($employeeId);

        $isOwn = $actingCompanyId !== null && (int) $employee->company_id === $actingCompanyId;
        $hasStintHere = $actingCompanyId !== null
            && $employee->companyHistory()->where('company_id', $actingCompanyId)->exists();

        // A company may open a worker who is currently theirs, or who previously
        // worked here (a closed stint in their history). Anyone else → 404, so
        // cross-company privacy is unchanged. Super Admin sees all.
        abort_unless($isSuperAdmin || $isOwn || $hasStintHere, 404);

        // READ-ONLY when the acting company is not the worker's CURRENT company
        // (they transferred away — this is a historical record). Every data tab
        // below is then pinned to the acting company's own stint via
        // $historyScope, so the new company's attendance/wages never leak here.
        $readOnly = ! $isOwn && ! $isSuperAdmin;
        $historyScope = $readOnly ? $actingCompanyId : null;

        // Banner: where they went + the date they left here.
        $transferBanner = null;
        if ($readOnly) {
            $leftStint = $employee->companyHistory()
                ->where('company_id', $actingCompanyId)
                ->whereNotNull('ended_at')
                ->orderByDesc('ended_at')
                ->first();
            $transferBanner = [
                'transferred_to' => $employee->company?->name,
                'on' => $leftStint?->ended_at?->toDateString(),
            ];
        }

        $canSeeWages = Gate::allows('payroll.view') || Gate::allows('employees.edit');
        $canSeeBank = $canSeeWages;
        // Write abilities collapse to false in the read-only historical view.
        $canWrite = fn (string $ability): bool => ! $readOnly && Gate::allows($ability);

        return Inertia::render('Employees/Detail', [
            'employee' => array_merge($employee->only([
                'id', 'employee_code', 'full_name', 'email', 'mobile', 'phone', 'city', 'address',
                'department', 'department_id', 'designation', 'designation_id', 'team_leader_id', 'active', 'is_contracted',
                'default_check_in', 'default_check_out', 'commission_percent',
                'has_driving_license', 'has_company_vehicle', 'can_use_vehicles', 'works_at_height', 'notes',
            ]), [
                'joining_date' => $employee->joining_date?->toDateString(),
                'leaving_date' => $employee->leaving_date?->toDateString(),
                'company' => $employee->company?->name,
                // Read-only historical view (transferred away) reads 'transferred';
                // otherwise the record's own active/inactive state.
                'status' => $readOnly ? 'transferred' : $employee->status(),
                'documents_pending_reupload' => $employee->documents_pending_reupload,
                'transferred_at' => $employee->transferred_at?->toDateString(),
                'previous_company' => $employee->previousCompany?->name,
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
            // Read-only historical view: the acting company is not this worker's
            // current company (they transferred away). Drives the banner + hides
            // every write control; the server also enforces it (data is pinned
            // to the acting company below, and writes 404 via the scoped routes).
            'readOnly' => $readOnly,
            'transferBanner' => $transferBanner,
            // Mobile PWA access (Worker PWA). Only whether a login exists and
            // which address it uses — never anything about the credential.
            'appAccess' => [
                'email' => $employee->user?->email,
                'active' => (bool) $employee->user?->active,
            ],
            // Employment History (Change 2) — every company stint of this person,
            // linked by person_uuid, newest company last.
            'employmentHistory' => $this->employmentHistoryPayload($request, $employee),
            // Active OUTBOUND deployment → "Desplegado a {host}" header badge.
            'activeDeployment' => $this->activeDeploymentPayload($employee),
            'documents' => $panel->forEntity($employee, 'employee'),
            'documentSets' => DocumentTypes::employee(),
            'documentFieldDefs' => DocumentTypes::fieldDefsMap('employee'),
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
            // Historial de Salario — the effective-dated wage timeline (pinned to
            // the acting company's stint in the read-only historical view).
            'wageHistory' => $canSeeWages ? $this->wageHistoryRows($employee, $wageRates, $historyScope) : [],
            // Asistencia tab — per-employee month grid + summary (attendance.view gated).
            'attendanceTab' => Gate::allows('attendance.view')
                ? $this->attendanceTabPayload($employee, $request->string('att_month')->value() ?: now()->format('Y-m'), $canSeeWages, $historyScope)
                : null,
            // No cell editing in the read-only historical view.
            'attendanceEditing' => Gate::allows('attendance.view') && ! $readOnly && $request->filled('att_edit')
                ? $this->attendanceEditingPayload($employee, (int) $request->integer('att_edit'), $canSeeWages)
                : null,
            'attendanceProjects' => Gate::allows('attendance.view') ? $this->attendanceProjects() : [],
            'attendanceEmployee' => Gate::allows('attendance.view') ? $this->attendanceEmployeePayload($employee, $canSeeWages, $wageRates) : null,
            'canManageAttendance' => ! $readOnly && Gate::allows('attendance.create'),
            // Equipamiento tab — kit issued to this worker (inventory.view gated).
            // Items only, never any cost figure — a worker's equipment is not pay.
            'equipmentTab' => Gate::allows('inventory.view') ? $this->equipmentTab($employee) : null,
            // Privacy-consent evidence (GDPR). Only meaningful for a worker with a
            // PWA login; the history is the append-only audit trail.
            'consent' => $this->consentPayload($employee),
            'canSeeWages' => $canSeeWages,
            'designationOptions' => ProjectDesignationRateController::optionsFor(app(CurrentCompany::class)->id()),
            'departmentOptions' => $this->departmentOptions(),
            // Feature 4 — companies this employee could be transferred to (admins
            // only). Never from the read-only historical view.
            'transferCompanies' => $readOnly ? [] : $this->transferCompanies($employee),
            // Every write ability collapses to false in the read-only historical
            // view; download stays (viewing a past document is a read). The
            // write ROUTES are also tenant-scoped, so a cross-company write 404s
            // regardless of the UI — this is defence in depth, not the only gate.
            'can' => [
                'edit' => $canWrite('employees.edit'),
                'delete' => $canWrite('employees.delete'),
                'transfer' => ! $readOnly && $this->canTransfer($request),
                'manageWages' => $canWrite('employees.edit'),
                'upload' => $canWrite('documents.upload'),
                'download' => Gate::allows('documents.download'),
                'deleteDocs' => $canWrite('documents.delete'),
                'editDocs' => $canWrite('documents.edit'),
            ],
        ]);
    }

    /** Whether the acting user may transfer employees between companies. */
    private function canTransfer(Request $request): bool
    {
        $user = $request->user();

        return Gate::allows('employees.edit')
            && $user !== null && ($user->isSuperAdmin() || $user->isCompanyAdmin());
    }

    /**
     * Target companies for a transfer — all active companies except the
     * employee's current one. Empty unless the user may transfer.
     *
     * @return list<array{id: int, name: string}>
     */
    private function transferCompanies(Employee $employee): array
    {
        if (! $this->canTransfer(request())) {
            return [];
        }

        return Company::query()->withoutGlobalScopes()
            ->where('id', '!=', $employee->company_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $c): array => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    /**
     * Equipamiento tab — kit issued to this worker. Current (still out) vs
     * history (fully returned), with the overdue flag and total count. Items
     * only — a worker's equipment carries no cost figure.
     *
     * @return array<string, mixed>
     */
    private function equipmentTab(Employee $employee): array
    {
        $issues = EmployeeEquipmentIssue::query()
            ->where('employee_id', $employee->id)
            ->with('item:id,name,sku,serial_number,unit')
            ->orderByDesc('issue_date')
            ->get();

        $row = fn (EmployeeEquipmentIssue $i): array => [
            'id' => $i->id,
            'item' => $i->item?->name,
            'serial' => $i->item?->serial_number,
            'unit' => $i->item?->unit,
            'issued_quantity' => (float) $i->issued_quantity,
            'returned_quantity' => (float) $i->returned_quantity,
            'outstanding' => $i->outstanding(),
            'issue_date' => $i->issue_date->toDateString(),
            'expected_return_date' => $i->expected_return_date?->toDateString(),
            'expiry_date' => $i->expiry_date?->toDateString(),
            'return_date' => $i->return_date?->toDateString(),
            'status' => $i->status->value,
            'overdue' => $i->isOverdue(),
            'expired' => $i->isExpired(),
            'notes' => $i->notes,
        ];

        $current = $issues->filter(fn (EmployeeEquipmentIssue $i) => $i->status !== EquipmentIssueStatus::Returned);
        $history = $issues->filter(fn (EmployeeEquipmentIssue $i) => $i->status === EquipmentIssueStatus::Returned);

        return [
            'current' => $current->map($row)->values()->all(),
            'history' => $history->map($row)->values()->all(),
            'count' => $current->count(),
            'overdue_count' => $current->filter(fn (EmployeeEquipmentIssue $i) => $i->isOverdue())->count(),
            // Estado EPIs — required-PPE compliance for this worker (alerts only).
            'ppe' => app(PpeComplianceService::class)->forEmployee($employee),
            'works_at_height' => $employee->works_at_height,
            // Historial de incidencias — damage / loss recorded against this worker.
            'incidents' => EquipmentIncident::query()
                ->where('employee_id', $employee->id)
                ->with('item:id,name,serial_number')
                ->orderByDesc('incident_date')->orderByDesc('id')
                ->get()
                ->map(fn (EquipmentIncident $inc): array => [
                    'id' => $inc->id,
                    'date' => $inc->incident_date->toDateString(),
                    'item' => $inc->item?->name,
                    'serial' => $inc->item?->serial_number,
                    'condition' => $inc->condition->value,
                    'quantity' => (float) $inc->quantity,
                    'notes' => $inc->notes,
                ])->values()->all(),
        ];
    }

    /**
     * Privacy-consent evidence for the Employee Detail page: the current status
     * + the full append-only history (each row is legal evidence).
     *
     * @return array<string, mixed>
     */
    private function consentPayload(Employee $employee): array
    {
        $row = fn (WorkerConsent $c): array => [
            'id' => $c->id,
            'version' => $c->consent_version,
            'consented_at' => $c->consented_at->toDateTimeString(),
            'timezone' => $c->timezone,
            'ip_address' => $c->ip_address,
            'user_agent' => $c->user_agent,
            'attendance' => $c->consent_attendance,
            'gps' => $c->consent_gps,
            'photo' => $c->consent_photo,
            'language' => $c->language,
            'revoked_at' => $c->revoked_at?->toDateTimeString(),
            'revoked_reason' => $c->revoked_reason,
        ];

        $active = $employee->activeConsent();

        return [
            'has_app_access' => $employee->user_id !== null,
            'current_version' => WorkerPrivacyNotice::currentVersion(),
            'accepted' => $active !== null,
            'active' => $active !== null ? $row($active) : null,
            'history' => $employee->consents()->get()->map($row)->all(),
        ];
    }

    /**
     * Admin forces a worker to re-accept the notice (e.g. after a policy change
     * for one person). Revokes the active consent; the worker is re-gated.
     */
    public function resetConsent(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('employees.edit');

        $user = $request->user();
        $who = $user !== null ? $user->name : 'admin';

        app(WorkerConsentService::class)->revoke($employee, "Reset by {$who} — worker must re-accept");

        return back()->with('success', __('ui.employees.consent_reset_done'));
    }

    /** Download one consent record as a PDF (legal evidence), gated + audited. */
    public function consentPdf(Employee $employee, WorkerConsent $consent, AuditLogger $audit): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('employees.view');
        abort_unless($consent->employee_id === $employee->id, 404);

        $audit->log('exported', $consent, null, null, 'Consent PDF', 'employees');

        return Pdf::loadView('exports.worker-consent', [
            'employee' => $employee,
            'consent' => $consent,
            'logo' => CompanyBranding::logoDataUri($employee->company),
        ])->download("consent-{$employee->employee_code}-{$consent->id}.pdf");
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
    private function wageHistoryRows(Employee $employee, WageRateService $wageRates, ?int $scopeCompanyId = null): array
    {
        $currentId = $wageRates->rateForDate($employee->id, now()->toDateString())?->id;

        return $wageRates->history($employee)
            // Read-only historical view: only the acting company's own rate
            // periods (the new company's rates never leak to the old one).
            ->when($scopeCompanyId !== null, fn ($rows) => $rows->where('company_id', $scopeCompanyId)->values())
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
     * The worker's current OUTBOUND deployment (if any) for the detail-header
     * "Desplegado a {host}" badge — host company, project, and dates.
     *
     * @return array{host_company: ?string, project: ?string, start: string, end: ?string}|null
     */
    private function activeDeploymentPayload(Employee $employee): ?array
    {
        $d = EmployeeDeployment::query()
            ->where('employee_id', $employee->id)
            ->where('status', DeploymentStatus::Active->value)
            ->with(['hostCompany:id,name', 'project:id,name'])
            ->orderByDesc('deployment_start')
            ->first();

        if ($d === null) {
            return null;
        }

        return [
            'host_company' => $d->hostCompany?->name,
            'project' => $d->project?->name,
            'start' => $d->deployment_start->toDateString(),
            'end' => $d->deployment_end?->toDateString(),
        ];
    }

    /**
     * The Asistencia tab: this employee's month grid + monthly summary. A
     * deployed worker's host-logged days count too, so the query drops the
     * tenant scope and pins to the employee (their pay is home-company's).
     *
     * @return array<string, mixed>
     */
    private function attendanceTabPayload(Employee $employee, string $month, bool $canSeeWages, ?int $scopeCompanyId = null): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // $scopeCompanyId is set only for the read-only historical view (an old
        // company looking at a worker who transferred away): pin the rows to
        // THAT company's stint so the new company's attendance never leaks.
        $records = Attendance::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->when($scopeCompanyId !== null, fn ($q) => $q->where('company_id', $scopeCompanyId))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with(['project:id,name', 'company:id,name'])
            ->get();

        // NET worked hours for this employee's company (full 08:00–17:00 → 8 h).
        $breakMinutes = app(AttendanceService::class)->breakDurationMinutes((int) $employee->company_id);

        $grid = [];
        foreach ($records as $r) {
            $grid[(int) $r->date->format('j')] = [
                'id' => $r->id,
                'status' => $r->status->value,
                'day_type' => $r->day_type?->value,
                'is_auto' => (bool) $r->is_auto_generated,
                'hours' => $r->displayHoursNet($breakMinutes),
                'quantity' => $r->quantity !== null ? (float) $r->quantity : null,
                'project' => $r->project?->name,
                'total' => $canSeeWages ? (float) $r->total_amount : null,
                // Logged at another (HOST) company while deployed → the home
                // admin sees "Desde {host} (desplegado)", never confusing it
                // with their own project's attendance.
                'deployed_from' => (int) $r->company_id !== (int) $employee->company_id
                    ? $r->company?->name : null,
            ];
        }

        // Live absences: an unrecorded past weekday (on/after joining) shows as
        // an auto-absence immediately — the same shared rule the worker PWA and
        // the standalone grid use, so all three agree without the nightly sweep.
        $today = now()->startOfDay();
        $workingDays = app(AttendanceService::class)
            ->workingDays((int) $employee->company_id);
        $virtualAbsences = 0;
        $cursor = $start->copy();
        // The read-only historical view ($scopeCompanyId set) shows ONLY the real
        // recorded rows of that stint — it never fabricates auto-absences, which
        // would otherwise mark every weekday after the worker left as "absent".
        while ($scopeCompanyId === null && $cursor->lte($end)) {
            $day = (int) $cursor->format('j');
            if (! isset($grid[$day]) && AttendanceAbsence::isUnrecordedAbsence($cursor, $today, $employee->joining_date, $employee->active, $employee->active_since, $employee->transferred_at, $workingDays)) {
                $grid[$day] = [
                    'id' => null, // no real row — clicking it opens "new entry"
                    'status' => 'absent',
                    'day_type' => null,
                    'is_auto' => true,
                    'hours' => 0.0,
                    'quantity' => null,
                    'project' => null,
                    'total' => $canSeeWages ? 0.0 : null,
                ];
                $virtualAbsences++;
            }
            $cursor->addDay();
        }

        $weekend = [];
        for ($d = 1; $d <= $start->daysInMonth; $d++) {
            $weekend[$d] = $start->copy()->day($d)->isWeekend();
        }

        // status is an enum cast, so compare ->value (the Phase 4 grid-summary bug).
        $worked = $records->filter(fn (Attendance $r) => in_array($r->status->value, ['present', 'late', 'early_leave'], true));
        $realAbsences = $records->filter(fn (Attendance $r) => $r->status->value === 'absent')->count();
        $realAutoAbsences = $records->filter(fn (Attendance $r) => $r->status->value === 'absent' && $r->is_auto_generated)->count();

        return [
            'month' => $month,
            'days_in_month' => $start->daysInMonth,
            'weekend' => $weekend,
            'grid' => $grid,
            'summary' => [
                'present' => $worked->count(),
                'half_days' => $worked->filter(fn (Attendance $r) => $r->day_type?->value === 'half')->count(),
                'hours' => round((float) $records->sum(fn (Attendance $r) => $r->displayHoursNet($breakMinutes)), 2),
                'overtime' => round((float) $records->sum(fn (Attendance $r) => (float) $r->overtime_hours), 2),
                'absences' => $realAbsences + $virtualAbsences,
                'auto_absences' => $realAutoAbsences + $virtualAbsences,
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
            'override_reason' => $a->override_reason,
            'is_paid' => $a->is_paid,
            'is_exception' => $a->is_exception,
            'exception_reason' => $a->exception_reason,
            'notes' => $a->notes,
        ];
    }

    /**
     * Employment History (Change 2, single-record model): every company stint of
     * this ONE employee record over time, read from employee_company_history.
     * The open stint (ended_at null) is the current company. `can_view` marks
     * stints at a company the viewer may see (own company, or any for a Super
     * Admin) — it does not drill into a separate record (there is only one).
     *
     * @return list<array<string, mixed>>
     */
    private function employmentHistoryPayload(Request $request, Employee $employee): array
    {
        $currentCompanyId = app(CurrentCompany::class)->id();
        $isSuperAdmin = $request->user()?->isSuperAdmin() ?? false;

        return $employee->companyHistory()
            ->with('company:id,name,brand_name')
            ->get()
            ->map(fn (EmployeeCompanyHistory $h): array => [
                'id' => $h->id,
                'company' => $h->company?->displayName(),
                'since' => $h->started_at->toDateString(),
                'until' => $h->ended_at?->toDateString(),
                'is_current' => $h->ended_at === null,
                'can_view' => $isSuperAdmin || $h->company_id === $currentCompanyId,
            ])
            ->all();
    }

    /**
     * Projects for the attendance modal's dropdown.
     *
     * @return list<array<string, mixed>>
     */
    private function attendanceProjects(): array
    {
        return Project::query()->active()->with('client:id,company_name')->orderBy('name')
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
    private function attendanceEmployeePayload(Employee $employee, bool $canSeeWages, WageRateService $wageRates): array
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
            // "Tarifa aplicada … (desde …)" — the current rate's effective-from.
            $row['rate_from'] = $wageRates->rateForDate($employee->id, now()->toDateString())?->effective_from?->toDateString();
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
     * Feature 4 — transfer the employee to another company (single-record model).
     * The service enforces the equipment + paid-payroll guards, flips this ONE
     * record's company_id in place, logs the company stint, and carries the wage
     * rate forward. Attendance rows keep the company they were logged under.
     */
    public function transfer(TransferEmployeeRequest $request, Employee $employee, EmployeeTransferService $service): RedirectResponse
    {
        $service->transfer(
            $employee,
            (int) $request->validated('to_company_id'),
            (string) $request->validated('transfer_date'),
        );

        return redirect()->route('employees.index')->with('success', __('ui.employees.transferred'));
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
}
