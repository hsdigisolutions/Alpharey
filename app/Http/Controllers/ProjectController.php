<?php

namespace App\Http\Controllers;

use App\Enums\ProductionTaskCategory;
use App\Enums\ProductionTaskStatus;
use App\Enums\ProjectContactRole;
use App\Enums\ProjectPriority;
use App\Enums\ProjectRateType;
use App\Enums\ProjectStatus;
use App\Enums\VatRate;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\ProjectContact;
use App\Models\ProjectDesignationRate;
use App\Models\Scopes\CompanyScope;
use App\Models\TaskProgress;
use App\Models\TaskTemplate;
use App\Services\Attendance\AttendanceService;
use App\Services\Documents\DocumentStatus;
use App\Services\ProductionTasks\ProductionReportService;
use App\Services\Reports\ProfitabilityService;
use App\Support\CurrentCompany;
use App\Support\DocumentPanelPayload;
use App\Support\DocumentTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screens 08/09 — Projects. Company-owned (global scope confines to the
 * active company); the client is shared. Table + kanban share one query.
 */
class ProjectController extends Controller
{
    private const SORTABLE = ['code', 'name', 'status', 'priority', 'start_date', 'budget', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('projects.view');

        $sort = in_array($request->string('sort')->value(), self::SORTABLE, true)
            ? $request->string('sort')->value()
            : 'created_at';
        $dir = $request->string('dir')->value() === 'asc' ? 'asc' : 'desc';
        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        $base = Project::query()
            ->with('client:id,name')
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = $request->string('search')->value();
                $q->where(fn (Builder $q) => $q
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")));
            })
            ->when($request->filled('client_id'), fn (Builder $q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn (Builder $q) => $q->where('priority', $request->string('priority')));

        // Kanban returns all rows grouped by status; table paginates. Both
        // map through $this->row() (a stable typed signature for Larastan).
        if ($request->string('view')->value() === 'kanban') {
            $grouped = (clone $base)->withCount('employeeRates')->orderByDesc('created_at')->get()
                ->groupBy(fn (Project $p) => $p->status->value)
                ->map(fn ($group) => $group->map(fn (Project $p) => $this->row($p))->values());

            $projects = null;
            $kanban = collect(ProjectStatus::cases())
                ->mapWithKeys(fn (ProjectStatus $s) => [$s->value => $grouped->get($s->value, collect())])
                ->all();
        } else {
            $projects = (clone $base)->withCount('employeeRates')->orderBy($sort, $dir)
                ->paginate($perPage)->withQueryString()->through(fn (Project $p) => $this->row($p));
            $kanban = null;
        }

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'kanban' => $kanban,
            'stats' => $this->projectStats(),
            'view' => $request->string('view')->value() === 'kanban' ? 'kanban' : 'table',
            'filters' => (object) $request->only(['search', 'client_id', 'status', 'priority', 'sort', 'dir', 'per_page']),
            'filterOptions' => [
                // List filter = all clients (browse projects of any client, incl.
                // inactive); the create form uses `activeClients` below.
                'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
                'statuses' => array_map(fn (ProjectStatus $s) => $s->value, ProjectStatus::cases()),
                'priorities' => array_map(fn (ProjectPriority $p) => $p->value, ProjectPriority::cases()),
                // Active employees of the acting company, for the manager/foreman
                // /safety/coordinator dropdowns on the create form.
                'employees' => self::employeeOptionsFor(app(CurrentCompany::class)->id()),
            ],
            // Selection list for the create-project form (Change 4): active only.
            'activeClients' => Client::query()->active()->orderBy('name')->get(['id', 'name']),
            'vatOptions' => VatRate::options(),
            'can' => [
                // Permission-only: shown to anyone who may create. The Vue gate
                // routes a company-less Super Admin to the picker before the
                // form; store() still guards company_id server-side.
                'create' => Gate::allows('projects.create'),
                'edit' => Gate::allows('projects.edit'),
                'delete' => Gate::allows('projects.delete'),
                'export' => Gate::allows('projects.export'),
            ],
        ]);
    }

    /**
     * Company-scoped project counts for the summary cards, in ONE query.
     *
     * @return array{total: int, active: int, completed: int, on_hold: int}
     */
    private function projectStats(): array
    {
        $row = Project::query()
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) as completed_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'on_hold' THEN 1 ELSE 0 END), 0) as on_hold_count")
            ->first();

        return [
            'total' => (int) ($row->total_count ?? 0),
            'active' => (int) ($row->active_count ?? 0),
            'completed' => (int) ($row->completed_count ?? 0),
            'on_hold' => (int) ($row->on_hold_count ?? 0),
        ];
    }

    /**
     * Active employees of a company, for the project manager/foreman/safety/
     * coordinator dropdowns. Tenant scope dropped + pinned to the company id so
     * it resolves under any session (a Super Admin browsing a specific company).
     *
     * @return list<array{id: int, name: string, designation: string|null}>
     */
    public static function employeeOptionsFor(?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }

        return Employee::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('active', true)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'designation'])
            ->map(fn (Employee $e): array => [
                'id' => $e->id,
                'name' => $e->full_name,
                'designation' => $e->designation,
            ])
            ->all();
    }

    /**
     * List-row shape shared by the table and kanban views.
     *
     * @return array<string, mixed>
     */
    private function row(Project $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'client' => $p->client?->name,
            'project_type' => $p->project_type,
            'status' => $p->status->value,
            'priority' => $p->priority->value,
            'billing_type' => $p->billing_type?->value,
            'workers' => $p->getAttribute('employee_rates_count') ?? 0,
            'start_date' => $p->start_date?->toDateString(),
            'end_date' => $p->end_date?->toDateString(),
            'budget' => $p->budget,
            'outsourced' => $p->outsourced,
        ];
    }

    public function show(Request $request, Project $project, DocumentStatus $status, ProfitabilityService $profitability, DocumentPanelPayload $panel): Response
    {
        Gate::authorize('projects.view');

        $canSeeWages = Gate::allows('payroll.view') || Gate::allows('employees.edit');
        $attMonth = $request->string('att_month')->value() ?: now()->format('Y-m');

        // The project's people, resolved to the linked employee (name · designation
        // · phone). Tenant scope dropped for display (SoftDeletes kept — a removed
        // employee falls back to the legacy free-text value instead).
        $project->loadMissing([
            'siteManager' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class),
            'foreman' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class),
            'safety' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class),
            'coordinatorEmployee' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class),
        ]);
        $contact = fn (?Employee $e, ?string $name, ?string $phone = null): ?array => $e !== null
            ? ['employee_id' => $e->id, 'name' => $e->full_name, 'designation' => $e->designation, 'phone' => $e->mobile ?: $e->phone]
            : ($name !== null && $name !== '' ? ['employee_id' => null, 'name' => $name, 'designation' => null, 'phone' => $phone] : null);

        return Inertia::render('Projects/Detail', [
            'project' => array_merge($project->only([
                'id', 'code', 'name', 'project_type', 'jefe_de_obra', 'jefe_phone',
                'jefe_email', 'encargado', 'seguridad', 'coordinator', 'budget',
                'estimated_hours', 'estimated_meters', 'outsourced', 'google_drive_link',
                'document_url', 'forma_de_pago', 'fecha_de_cobro', 'color_code', 'description',
            ]), [
                'client_hour_rate' => $project->client_hour_rate !== null ? (float) $project->client_hour_rate : null,
                'client_meter_rate' => $project->client_meter_rate !== null ? (float) $project->client_meter_rate : null,
                'outsource_cost' => $project->outsource_cost !== null ? (float) $project->outsource_cost : null,
                'latitude' => $project->latitude !== null ? (float) $project->latitude : null,
                'longitude' => $project->longitude !== null ? (float) $project->longitude : null,
                'geofence_radius' => $project->geofence_radius,
                'status' => $project->status->value,
                'priority' => $project->priority->value,
                'billing_type' => $project->billing_type?->value,
                'vat_rate' => $project->vat_rate?->value,
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'client_id' => $project->client_id,
                'client' => $project->client?->name,
                'company' => $project->company?->name,
                // Manager FKs for the edit-form prefill …
                'site_manager_id' => $project->site_manager_id,
                'foreman_id' => $project->foreman_id,
                'safety_id' => $project->safety_id,
                'coordinator_id' => $project->coordinator_id,
                // … and the resolved people for the Site-contacts display (linked
                // employee, else the legacy free-text fallback).
                'site_manager' => $contact($project->siteManager, $project->jefe_de_obra, $project->jefe_phone),
                'foreman' => $contact($project->foreman, $project->encargado),
                'safety' => $contact($project->safety, $project->seguridad),
                'coordinator_contact' => $contact($project->coordinatorEmployee, $project->coordinator),
            ]),
            'workers' => $project->employeeRates()->with('employee:id,full_name,designation,wage_type')->get()
                ->map(fn ($rate) => [
                    'id' => $rate->id,
                    'employee_id' => $rate->employee_id,
                    'name' => $rate->employee?->full_name,
                    'designation' => $rate->employee?->designation,
                    'wage_type' => $rate->wage_type,
                    'project_rate' => $canSeeWages ? $rate->getAttribute('project_rate') : null,
                ]),
            'documents' => $panel->forEntity($project, 'project'),
            'documentSets' => DocumentTypes::project(),
            'documentFieldDefs' => DocumentTypes::fieldDefsMap('project'),
            'remarks' => $project->remarks()->with('author:id,name')->orderByDesc('noted_at')->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'type' => $r->type, 'body' => $r->body,
                    'noted_at' => $r->noted_at->toDateTimeString(), 'author' => $r->author?->name,
                ]),
            'alerts' => $project->alerts()->orderByDesc('scheduled_date')->get(['id', 'alert_type', 'title', 'message', 'scheduled_date', 'status']),
            'availableEmployees' => Employee::query()->where('active', true)
                ->whereNotIn('id', $project->employeeRates()->pluck('employee_id'))
                ->orderBy('full_name')->get(['id', 'full_name']),
            // Edit-form client list: active only (Change 4), but always include
            // this project's current client even if it has since gone inactive,
            // so editing never silently drops the assigned client.
            'clients' => Client::query()
                ->where(fn ($q) => $q->where('active', true)->orWhere('id', $project->client_id))
                ->orderBy('name')->get(['id', 'name']),
            'vatOptions' => VatRate::options(),
            // Facturas / Gastos tabs — the standalone screens stay canonical;
            // these are a read-only view of the same rows scoped to the project,
            // gated by the finance modules' own view rights.
            'invoices' => Gate::allows('invoices.view') ? $this->projectInvoices($project) : [],
            'invoiceSummary' => Gate::allows('invoices.view') ? $this->invoiceSummary($project) : null,
            'canCreateInvoice' => Gate::allows('invoices.create'),
            'expenses' => Gate::allows('expenses.view') ? $this->projectExpenses($project) : [],
            // Category breakdown of this project's expenses (split-aware).
            'expenseBreakdown' => Gate::allows('expenses.view') ? $this->projectExpenseBreakdown($project) : [],
            'canViewInvoices' => Gate::allows('invoices.view'),
            'canViewExpenses' => Gate::allows('expenses.view'),
            // Profitability (P&L) for the Resumen tab — labour cost + margins,
            // so gated by the same wage right as the workers' rates.
            'canSeeWages' => $canSeeWages,
            'profitability' => $canSeeWages ? $profitability->forProject($project, null, null, false) : null,
            // Feature 3 — daily / monthly production P&L (wage-gated).
            'dailyPnl' => $canSeeWages ? $profitability->dailyPnl($project, null, null) : null,
            // Feature 2 — per-designation rates + the designation catalogue.
            'designationRates' => $project->designationRates()->with('designation:id,name')->get()
                ->map(fn (ProjectDesignationRate $r) => [
                    'id' => $r->id,
                    'designation_id' => $r->designation_id,
                    'designation' => $r->designation?->name,
                    'client_rate' => (float) $r->client_rate,
                    'worker_rate' => (float) $r->worker_rate,
                    'rate_type' => $r->rate_type->value,
                ]),
            'designations' => ProjectDesignationRateController::optionsFor($project->company_id),
            'rateTypes' => array_map(fn (ProjectRateType $t) => $t->value, ProjectRateType::cases()),
            // Active employees of the project's company, for the manager dropdowns.
            'employeeOptions' => self::employeeOptionsFor($project->company_id),
            // Client-side contacts for this project (supervisor / engineer / PM / other).
            'projectContacts' => $project->contacts()->orderBy('name')->get()
                ->map(fn (ProjectContact $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'role' => $c->role->value,
                    'phone' => $c->phone,
                    'email' => $c->email,
                    'notes' => $c->notes,
                ]),
            'contactRoles' => array_map(fn (ProjectContactRole $r) => $r->value, ProjectContactRole::cases()),
            // Attendance tab — this project's rows for the selected month + entry data.
            'projectAttendance' => Gate::allows('attendance.view') ? $this->projectAttendance($project, $attMonth, $canSeeWages) : null,
            'attendanceMonth' => $attMonth,
            'attendanceEntryEmployees' => Gate::allows('attendance.create') ? $this->attendanceEntryEmployees($canSeeWages) : [],
            'canManageAttendance' => Gate::allows('attendance.create'),
            'canSeeAttendance' => Gate::allows('attendance.view'),
            // Measurements tab was removed from the project view (2026-09) — the
            // standalone /measurements screen owns that workflow, and per-meter
            // income still reads the measurements table directly in
            // ProfitabilityService, so no payload is needed here.
            // Tareas tab — production tasks (internal planned-vs-actual tracker).
            'projectTasks' => Gate::allows('production_tasks.view') ? $this->projectTasks($project) : null,
            'taskCategories' => array_map(fn (ProductionTaskCategory $c) => $c->value, ProductionTaskCategory::cases()),
            'taskStatuses' => array_map(fn (ProductionTaskStatus $s) => $s->value, ProductionTaskStatus::cases()),
            'taskTemplates' => Gate::allows('production_tasks.view') ? $this->taskTemplates() : [],
            'taskReport' => Gate::allows('production_tasks.view') ? $this->taskReport($project) : null,
            'canManageTasks' => [
                'view' => Gate::allows('production_tasks.view'),
                'create' => Gate::allows('production_tasks.create'),
                'edit' => Gate::allows('production_tasks.edit'),
                'delete' => Gate::allows('production_tasks.delete'),
            ],
            'can' => [
                'edit' => Gate::allows('projects.edit'),
                'delete' => Gate::allows('projects.delete'),
                'upload' => Gate::allows('documents.upload'),
                'download' => Gate::allows('documents.download'),
                'deleteDocs' => Gate::allows('documents.delete'),
                'editDocs' => Gate::allows('documents.edit'),
            ],
        ]);
    }

    /**
     * Invoices raised against this project (tenant-scoped, newest first).
     *
     * @return list<array<string, mixed>>
     */
    private function projectInvoices(Project $project): array
    {
        return Invoice::query()
            ->where('project_id', $project->id)
            ->with('client:id,name')
            ->orderByDesc('invoice_date')
            ->get()
            ->map(fn (Invoice $i): array => [
                'id' => $i->id,
                'number' => $i->number,
                'party' => $i->client?->name,
                'date' => $i->invoice_date->toDateString(),
                'total' => (float) $i->total,
                'status' => $i->payment_status->value,
            ])
            ->all();
    }

    /**
     * Invoiced / paid / pending totals for this project's invoices.
     *
     * @return array{invoiced: float, paid: float, pending: float}
     */
    private function invoiceSummary(Project $project): array
    {
        $invoices = Invoice::query()->where('project_id', $project->id)->get(['total', 'paid_amount']);
        $invoiced = round((float) $invoices->sum(fn (Invoice $i): float => (float) $i->total), 2);
        $paid = round((float) $invoices->sum(fn (Invoice $i): float => (float) $i->paid_amount), 2);

        return ['invoiced' => $invoiced, 'paid' => $paid, 'pending' => round($invoiced - $paid, 2)];
    }

    /**
     * Expenses booked against this project (tenant-scoped, newest first).
     *
     * @return list<array<string, mixed>>
     */
    private function projectExpenses(Project $project): array
    {
        return Expense::query()
            ->where('project_id', $project->id)
            ->with('vendor:id,name')
            ->orderByDesc('date')
            ->get()
            ->map(fn (Expense $e): array => [
                'id' => $e->id,
                'number' => $e->number,
                'party' => $e->vendor?->name,
                'date' => $e->date->toDateString(),
                'total' => (float) $e->total,
                'status' => $e->payment_status->value,
            ])
            ->all();
    }

    /**
     * Category breakdown of this project's expenses — split-aware (a
     * multi-category expense distributes across its splits; a single-category
     * one attributes its whole total). Sorted by amount, with the % of total.
     *
     * @return list<array{category: string, total: float, pct: float}>
     */
    private function projectExpenseBreakdown(Project $project): array
    {
        $byCategory = [];
        $grand = 0.0;

        Expense::query()
            ->where('project_id', $project->id)
            ->with(['category:id,name', 'splits.category:id,name'])
            ->get()
            ->each(function (Expense $e) use (&$byCategory, &$grand): void {
                $grand += (float) $e->total;
                $breakdown = $e->categoryBreakdown();

                if ($breakdown === null) {
                    $byCategory['—'] = ($byCategory['—'] ?? 0.0) + (float) $e->total;

                    return;
                }

                foreach ($breakdown as $slice) {
                    $name = $slice['category'] ?? '—';
                    $byCategory[$name] = ($byCategory[$name] ?? 0.0) + $slice['amount'];
                }
            });

        $grand = round($grand, 2);
        $rows = [];
        foreach ($byCategory as $name => $amount) {
            $rows[] = [
                'category' => (string) $name,
                'total' => round($amount, 2),
                'pct' => $grand > 0.0 ? round($amount / $grand * 100, 1) : 0.0,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $rows;
    }

    /**
     * This project's attendance for one month + a period summary. Wage figures
     * (rate, total, labour cost) are null unless the viewer may see pay.
     *
     * @return array<string, mixed>
     */
    private function projectAttendance(Project $project, string $month, bool $canSeeWages): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $rows = Attendance::query()
            ->where('project_id', $project->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with(['employee:id,full_name,designation'])
            ->orderBy('date')
            ->get();

        $worked = $rows->filter(fn (Attendance $r) => in_array($r->status->value, ['present', 'late', 'early_leave'], true));

        // NET worked hours for this project's company (full 08:00–17:00 → 8 h).
        $breakMinutes = app(AttendanceService::class)->breakDurationMinutes((int) $project->company_id);

        return [
            'records' => $rows->map(fn (Attendance $r): array => [
                'id' => $r->id,
                'date' => $r->date->toDateString(),
                'employee' => $r->employee?->full_name,
                'designation' => $r->employee?->designation,
                'day_type' => $r->day_type?->value,
                'check_in' => $r->check_in,
                'check_out' => $r->check_out,
                'hours' => $r->displayHoursNet($breakMinutes),
                'status' => $r->status->value,
                'rate' => $canSeeWages ? (float) ($r->wage_rate_snapshot ?? 0) : null,
                'total' => $canSeeWages ? (float) $r->total_amount : null,
            ])->values()->all(),
            'summary' => [
                'workers' => $worked->pluck('employee_id')->unique()->count(),
                'days' => $worked->count(),
                'hours' => round((float) $worked->sum(fn (Attendance $r) => $r->displayHoursNet($breakMinutes)), 2),
                'labour_cost' => $canSeeWages ? round((float) $worked->sum(fn (Attendance $r) => (float) $r->total_amount), 2) : null,
            ],
        ];
    }

    /**
     * Active employees with their rate fields (wage-gated), for the attendance
     * New-Entry modal on the project tab.
     *
     * @return list<array<string, mixed>>
     */
    private function attendanceEntryEmployees(bool $canSeeWages): array
    {
        return Employee::query()->where('active', true)->orderBy('full_name')
            ->get(['id', 'full_name', 'designation', 'wage_type', 'wage_rate', 'daily_wage', 'per_meter_rate'])
            ->map(function (Employee $e) use ($canSeeWages): array {
                $row = [
                    'id' => $e->id, 'full_name' => $e->full_name,
                    'designation' => $e->designation, 'wage_type' => $e->wage_type?->value,
                ];
                if ($canSeeWages) {
                    $row['daily_rate'] = $e->daily_wage !== null ? round((float) $e->daily_wage, 2) : null;
                    $row['hourly_rate_raw'] = $e->wage_rate !== null ? round((float) $e->wage_rate, 2) : null;
                    $row['per_meter_rate'] = $e->per_meter_rate !== null ? round((float) $e->per_meter_rate, 2) : null;
                }

                return $row;
            })->all();
    }

    /**
     * This project's production tasks + the advisory weighted overall progress.
     *
     * @return array<string, mixed>
     */
    private function projectTasks(Project $project): array
    {
        $all = ProductionTask::query()
            ->where('project_id', $project->id)
            ->with(['progress' => fn ($q) => $q->with('employee:id,full_name')->orderByDesc('date')->orderByDesc('id')])
            ->orderBy('category')->orderBy('name')
            ->get();

        $childrenByParent = $all->whereNotNull('parent_task_id')->groupBy('parent_task_id');
        $topLevel = $all->whereNull('parent_task_id')->values();

        // Weighted overall progress (advisory) counts TOP-LEVEL tasks only —
        // sub-tasks roll up into their parent, which participates in the project.
        $weightSum = (float) $topLevel->sum(fn (ProductionTask $t) => (float) $t->weightage);
        if ($weightSum > 0) {
            $overall = round((float) $topLevel->sum(fn (ProductionTask $t) => $t->progressPercent() * (float) $t->weightage) / $weightSum, 1);
        } else {
            $overall = $topLevel->isNotEmpty() ? round((float) $topLevel->avg(fn (ProductionTask $t) => $t->progressPercent()), 1) : 0.0;
        }

        return [
            'tasks' => $topLevel->map(function (ProductionTask $t) use ($childrenByParent): array {
                $row = $this->taskRow($t);
                $children = $childrenByParent->get($t->id);
                $row['children'] = $children === null ? [] : $children
                    ->map(fn (ProductionTask $c): array => $this->taskRow($c))->values()->all();

                return $row;
            })->values()->all(),
            'overall_progress' => min(100.0, $overall),
            // Advisory: warn (do not block) when the weightage does not sum to 100.
            'weightage_sum' => round($weightSum, 2),
        ];
    }

    /**
     * Shape a single production task (parent or sub-task) for the Tareas tab.
     *
     * @return array<string, mixed>
     */
    private function taskRow(ProductionTask $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'parent_task_id' => $t->parent_task_id,
            'category' => $t->category->value,
            'house_number' => $t->house_number,
            'unit' => $t->unit,
            'unit_price' => (float) $t->unit_price,
            'client_rate' => $t->client_rate !== null ? (float) $t->client_rate : null,
            'planned_quantity' => (float) $t->planned_quantity,
            'completed_quantity' => (float) $t->completed_quantity,
            'weightage' => (float) $t->weightage,
            'status' => $t->status->value,
            'progress' => $t->progressPercent(),
            'health' => $t->health(),
            'notes' => $t->notes,
            // Daily-production history, grouped into multi-worker batches.
            'batches' => $this->taskBatches($t),
        ];
    }

    /**
     * A task's daily-production history, grouped into multi-worker batches
     * (newest first — the rows arrive pre-ordered by the eager load).
     *
     * @return list<array<string, mixed>>
     */
    private function taskBatches(ProductionTask $task): array
    {
        $batches = [];

        foreach ($task->progress->groupBy('batch_id') as $rows) {
            /** @var Collection<int, TaskProgress> $rows */
            $first = $rows->first();
            if ($first === null) {
                continue;
            }

            $batches[] = [
                'batch_id' => $first->batch_id,
                'date' => $first->date->toDateString(),
                'quantity' => round((float) $rows->sum(fn (TaskProgress $r) => (float) $r->quantity), 2),
                'workers' => $rows->map(fn (TaskProgress $r) => $r->employee?->full_name)->filter()->values()->all(),
                'notes' => $first->notes,
                'photo_id' => $rows->firstWhere('photo_path', '!=', null)?->id,
            ];
        }

        return $batches;
    }

    /**
     * Phase E — production reporting (planned-vs-actual, per-worker, trend) for
     * the Tareas tab.
     *
     * @return array<string, mixed>
     */
    private function taskReport(Project $project): array
    {
        $service = app(ProductionReportService::class);

        return [
            'planned_vs_actual' => $service->plannedVsActual($project),
            'per_worker' => $service->perWorker($project),
            'trend' => $service->trend($project),
        ];
    }

    /**
     * The company's active task templates — prefill the bulk-add grid.
     *
     * @return list<array<string, mixed>>
     */
    private function taskTemplates(): array
    {
        return TaskTemplate::query()
            ->active()
            ->orderBy('category')->orderBy('name')
            ->get()
            ->map(fn (TaskTemplate $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'category' => $t->category->value,
                'unit' => $t->unit,
                'unit_price' => $t->unit_price !== null ? (float) $t->unit_price : null,
                'planned_quantity' => $t->planned_quantity !== null ? (float) $t->planned_quantity : null,
                'weightage' => $t->weightage !== null ? (float) $t->weightage : null,
                'description' => $t->description,
            ])->values()->all();
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $companyId = app(CurrentCompany::class)->id();
        abort_if($companyId === null, 403);

        $project = new Project($request->validated());
        $project->company_id = $companyId;
        // Admin may type a code; blank falls back to the auto-generated one.
        $code = trim((string) $request->input('code', ''));
        $project->code = $code !== '' ? $code : Project::nextCode();
        $project->save();

        return redirect()->route('projects.show', $project)->with('success', __('ui.projects.saved'));
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        // Code is editable after creation; a blank field keeps the current code.
        $code = trim((string) $request->input('code', ''));
        if ($code !== '' && $code !== $project->code) {
            $project->code = $code;
            $project->save();
        }

        return back()->with('success', __('ui.projects.saved'));
    }

    public function updateStatus(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('projects.edit');

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ProjectStatus::class)],
        ]);

        $project->update(['status' => $validated['status']]);

        return back()->with('success', __('ui.projects.saved'));
    }

    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('projects.delete');

        $project->delete();

        return redirect()->route('projects.index')->with('success', __('ui.projects.deleted'));
    }
}
