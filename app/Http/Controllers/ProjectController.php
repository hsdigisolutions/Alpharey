<?php

namespace App\Http\Controllers;

use App\Enums\MeasurementStatus;
use App\Enums\MeasurementType;
use App\Enums\ProductionTaskCategory;
use App\Enums\ProductionTaskStatus;
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
use App\Models\Measurement;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\ProjectDesignationRate;
use App\Models\TaskTemplate;
use App\Services\Documents\DocumentStatus;
use App\Services\Reports\ProfitabilityService;
use App\Support\CurrentCompany;
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
            'view' => $request->string('view')->value() === 'kanban' ? 'kanban' : 'table',
            'filters' => $request->only(['search', 'client_id', 'status', 'priority', 'sort', 'dir', 'per_page']),
            'filterOptions' => [
                'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
                'statuses' => array_map(fn (ProjectStatus $s) => $s->value, ProjectStatus::cases()),
                'priorities' => array_map(fn (ProjectPriority $p) => $p->value, ProjectPriority::cases()),
            ],
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

    public function show(Request $request, Project $project, DocumentStatus $status, ProfitabilityService $profitability): Response
    {
        Gate::authorize('projects.view');

        $canSeeWages = Gate::allows('payroll.view') || Gate::allows('employees.edit');
        $attMonth = $request->string('att_month')->value() ?: now()->format('Y-m');

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
                'status' => $project->status->value,
                'priority' => $project->priority->value,
                'billing_type' => $project->billing_type?->value,
                'vat_rate' => $project->vat_rate?->value,
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'client_id' => $project->client_id,
                'client' => $project->client?->name,
                'company' => $project->company?->name,
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
            'documents' => $project->documents()->where('is_current', true)->get()
                ->map(function ($d) use ($status): array {
                    [$state, $daysLeft] = $status->of($d);

                    return [
                        'id' => $d->id, 'category' => $d->category, 'type_key' => $d->type_key,
                        'name' => $d->name, 'original_name' => $d->original_name,
                        'has_file' => $d->getAttribute('file_path') !== null, 'has_flag' => $d->has_flag,
                        'expiry_date' => $d->expiry_date?->toDateString(), 'version' => $d->version,
                        'status' => $state, 'days_left' => $daysLeft,
                    ];
                }),
            'documentSets' => DocumentTypes::project(),
            'remarks' => $project->remarks()->with('author:id,name')->orderByDesc('noted_at')->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'type' => $r->type, 'body' => $r->body,
                    'noted_at' => $r->noted_at->toDateTimeString(), 'author' => $r->author?->name,
                ]),
            'alerts' => $project->alerts()->orderByDesc('scheduled_date')->get(['id', 'alert_type', 'title', 'message', 'scheduled_date', 'status']),
            'availableEmployees' => Employee::query()->where('active', true)
                ->whereNotIn('id', $project->employeeRates()->pluck('employee_id'))
                ->orderBy('full_name')->get(['id', 'full_name']),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'vatOptions' => VatRate::options(),
            // Facturas / Gastos tabs — the standalone screens stay canonical;
            // these are a read-only view of the same rows scoped to the project,
            // gated by the finance modules' own view rights.
            'invoices' => Gate::allows('invoices.view') ? $this->projectInvoices($project) : [],
            'expenses' => Gate::allows('expenses.view') ? $this->projectExpenses($project) : [],
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
            // Attendance tab — this project's rows for the selected month + entry data.
            'projectAttendance' => Gate::allows('attendance.view') ? $this->projectAttendance($project, $attMonth, $canSeeWages) : null,
            'attendanceMonth' => $attMonth,
            'attendanceEntryEmployees' => Gate::allows('attendance.create') ? $this->attendanceEntryEmployees($canSeeWages) : [],
            'canManageAttendance' => Gate::allows('attendance.create'),
            'canSeeAttendance' => Gate::allows('attendance.view'),
            // Measurements tab — this project's records + summary + entry catalogue.
            'projectMeasurements' => Gate::allows('measurements.view') ? $this->projectMeasurements($project) : null,
            'measurementTypes' => array_map(fn (MeasurementType $t) => $t->value, MeasurementType::cases()),
            'measurementEmployees' => Gate::allows('measurements.view')
                ? Employee::query()->where('active', true)->orderBy('full_name')
                    ->get(['id', 'full_name', 'designation'])
                    ->map(fn (Employee $e) => ['id' => $e->id, 'full_name' => $e->full_name, 'designation' => $e->designation])->all()
                : [],
            'canManageMeasurements' => [
                'view' => Gate::allows('measurements.view'),
                'create' => Gate::allows('measurements.create'),
                'edit' => Gate::allows('measurements.edit'),
                'delete' => Gate::allows('measurements.delete'),
                'approve' => Gate::allows('measurements.approve'),
            ],
            // Tareas tab — production tasks (internal planned-vs-actual tracker).
            'projectTasks' => Gate::allows('production_tasks.view') ? $this->projectTasks($project) : null,
            'taskCategories' => array_map(fn (ProductionTaskCategory $c) => $c->value, ProductionTaskCategory::cases()),
            'taskStatuses' => array_map(fn (ProductionTaskStatus $s) => $s->value, ProductionTaskStatus::cases()),
            'taskTemplates' => Gate::allows('production_tasks.view') ? $this->taskTemplates() : [],
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

        return [
            'records' => $rows->map(fn (Attendance $r): array => [
                'id' => $r->id,
                'date' => $r->date->toDateString(),
                'employee' => $r->employee?->full_name,
                'designation' => $r->employee?->designation,
                'day_type' => $r->day_type?->value,
                'check_in' => $r->check_in,
                'check_out' => $r->check_out,
                'hours' => (float) $r->hours_worked,
                'status' => $r->status->value,
                'rate' => $canSeeWages ? (float) ($r->wage_rate_snapshot ?? 0) : null,
                'total' => $canSeeWages ? (float) $r->total_amount : null,
            ])->values()->all(),
            'summary' => [
                'workers' => $worked->pluck('employee_id')->unique()->count(),
                'days' => $worked->count(),
                'hours' => round((float) $worked->sum(fn (Attendance $r) => (float) $r->hours_worked), 2),
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
     * This project's measurements + a summary (approved / pending quantity and
     * whether they feed billing — a per_meter project).
     *
     * @return array<string, mixed>
     */
    private function projectMeasurements(Project $project): array
    {
        $rows = Measurement::query()
            ->where('project_id', $project->id)
            ->with(['employee:id,full_name,designation'])
            ->orderByDesc('date')
            ->get();

        $qty = fn (Collection $c): float => round((float) $c->sum(fn (Measurement $m) => (float) $m->quantity), 2);

        // A4 — per-worker breakdown of approved / pending / rejected metres.
        $perWorker = $rows->groupBy('employee_id')->map(function (Collection $g) use ($qty): array {
            $first = $g->first();

            return [
                'employee_id' => $first->employee_id,
                'employee' => $first->employee_id !== null ? ($first->employee->full_name ?? '—') : '—',
                'approved' => $qty($g->where('status', MeasurementStatus::Approved)),
                'pending' => $qty($g->where('status', MeasurementStatus::Pending)),
                'rejected' => $qty($g->where('status', MeasurementStatus::Rejected)),
                'total' => $qty($g),
            ];
        })->sortByDesc('total')->values()->all();

        return [
            'records' => $rows->map(fn (Measurement $m): array => [
                'id' => $m->id,
                'date' => $m->date->toDateString(),
                'employee_id' => $m->employee_id,
                'employee' => $m->employee?->full_name,
                'designation' => $m->employee?->designation,
                'quantity' => (float) $m->quantity,
                'unit' => $m->unit,
                'type' => $m->measurement_type->value,
                'status' => $m->status->value,
                'approved' => $m->approved,
                'rejection_reason' => $m->rejection_reason,
                'notes' => $m->notes,
            ])->values()->all(),
            'summary' => [
                'approved_qty' => $qty($rows->where('status', MeasurementStatus::Approved)),
                'pending_qty' => $qty($rows->where('status', MeasurementStatus::Pending)),
                'rejected_qty' => $qty($rows->where('status', MeasurementStatus::Rejected)),
                'billing_linked' => $project->billing_type?->value === 'per_meter',
            ],
            'per_worker' => $perWorker,
        ];
    }

    /**
     * This project's production tasks + the advisory weighted overall progress.
     *
     * @return array<string, mixed>
     */
    private function projectTasks(Project $project): array
    {
        $tasks = ProductionTask::query()
            ->where('project_id', $project->id)
            ->orderBy('category')->orderBy('name')
            ->get();

        // Weighted overall progress (advisory): Σ(pct × weightage) / Σ(weightage);
        // if no weightage is set, fall back to the simple mean of the percentages.
        $weightSum = (float) $tasks->sum(fn (ProductionTask $t) => (float) $t->weightage);
        if ($weightSum > 0) {
            $overall = round((float) $tasks->sum(fn (ProductionTask $t) => $t->progressPercent() * (float) $t->weightage) / $weightSum, 1);
        } else {
            $overall = $tasks->isNotEmpty() ? round((float) $tasks->avg(fn (ProductionTask $t) => $t->progressPercent()), 1) : 0.0;
        }

        return [
            'tasks' => $tasks->map(fn (ProductionTask $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'category' => $t->category->value,
                'house_number' => $t->house_number,
                'unit' => $t->unit,
                'unit_price' => (float) $t->unit_price,
                'planned_quantity' => (float) $t->planned_quantity,
                'completed_quantity' => (float) $t->completed_quantity,
                'weightage' => (float) $t->weightage,
                'status' => $t->status->value,
                'progress' => $t->progressPercent(),
                'health' => $t->health(),
                'notes' => $t->notes,
            ])->values()->all(),
            'overall_progress' => min(100.0, $overall),
            // Advisory: warn (do not block) when the weightage does not sum to 100.
            'weightage_sum' => round($weightSum, 2),
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
        $project->code = Project::nextCode($companyId);
        $project->save();

        return redirect()->route('projects.show', $project)->with('success', __('ui.projects.saved'));
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

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
