<?php

namespace App\Http\Controllers;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Enums\VatRate;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Services\Documents\DocumentStatus;
use App\Support\CurrentCompany;
use App\Support\DocumentTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                // Creating needs a company to own the project; a Super Admin
                // browsing all companies has none — don't offer a dead end
                // (decision 27, same as invoices/expenses).
                'create' => Gate::allows('projects.create') && app(CurrentCompany::class)->id() !== null,
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

    public function show(Project $project, DocumentStatus $status): Response
    {
        Gate::authorize('projects.view');

        $canSeeWages = Gate::allows('payroll.view') || Gate::allows('employees.edit');

        return Inertia::render('Projects/Detail', [
            'project' => array_merge($project->only([
                'id', 'code', 'name', 'project_type', 'jefe_de_obra', 'jefe_phone',
                'jefe_email', 'encargado', 'seguridad', 'coordinator', 'budget',
                'estimated_hours', 'estimated_meters', 'outsourced', 'google_drive_link',
                'document_url', 'forma_de_pago', 'fecha_de_cobro', 'color_code', 'description',
            ]), [
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
