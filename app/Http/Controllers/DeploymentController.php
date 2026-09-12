<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentRateType;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationType;
use App\Http\Requests\Deployments\StoreDeploymentRequest;
use App\Http\Requests\Deployments\UpdateDeploymentRequest;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\DeploymentCharge;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Services\Audit\AuditLogger;
use App\Services\Deployments\DeploymentChargeService;
use App\Services\Deployments\DeploymentSettlementService;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * REQUIREMENTS.md §3 — cross-company employee deployments (the signature
 * feature). A host-company admin deploys an employee from another company
 * onto their own project. Option A only: the employee stays on the home
 * payroll; the host is cross-charged (DeploymentChargeService).
 *
 * Deployments span two companies, so visibility is home-OR-host (or Super
 * Admin), enforced via EmployeeDeployment::scopeVisibleTo.
 */
class DeploymentController extends Controller
{
    public function index(Request $request, DeploymentChargeService $charges): Response
    {
        Gate::authorize('deployments.view');

        $companyId = app(CurrentCompany::class)->id();
        $isSuperAdmin = $request->user()?->isSuperAdmin() ?? false;

        $paginator = EmployeeDeployment::query()
            ->visibleTo($companyId)
            ->with(['employee:id,full_name', 'homeCompany:id,name', 'hostCompany:id,name', 'project:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('deployment_start')
            ->paginate(25)
            ->withQueryString();

        // Settlement rows for the whole page in ONE query (no N+1) — keyed by
        // deployment id. The charge is the cross-company record; its settlement
        // columns drive the payable/receivable + paid history on both sides.
        $chargeMap = DeploymentCharge::query()
            ->whereIn('employee_deployment_id', $paginator->getCollection()->pluck('id')->all())
            ->get()
            ->keyBy('employee_deployment_id');

        // The formal inter-company invoice for each charge (the home issues it;
        // the host reads it via the deployment, never its own Invoices module).
        // Unscoped: the link is by charge id, and both sides may open the PDF.
        $invoiceMap = Invoice::query()->withoutGlobalScopes()
            ->whereIn('deployment_charge_id', $chargeMap->pluck('id')->all())
            ->get(['id', 'number', 'deployment_charge_id'])
            ->keyBy('deployment_charge_id');

        $deployments = $paginator
            ->through(function (EmployeeDeployment $d) use ($charges, $companyId, $isSuperAdmin, $chargeMap, $invoiceMap): array {
                $summary = $charges->summary($d);
                $charge = $chargeMap->get($d->id);
                $invoice = $charge !== null ? $invoiceMap->get($charge->id) : null;

                // A charge becomes payable/settleable only once the deployment
                // COMPLETES (the charge locks and invoiced_at is stamped); while
                // active it is still accruing.
                $isPayable = $charge !== null && $charge->invoiced_at !== null;
                $settlement = $charge === null
                    ? ['status' => 'unpaid', 'is_payable' => false, 'invoiced_at' => null, 'paid_at' => null]
                    : [
                        'status' => $charge->settlement_status,
                        'is_payable' => $isPayable,
                        'invoiced_at' => $charge->invoiced_at?->toDateString(),
                        'paid_at' => $charge->paid_at?->toDateString(),
                    ];

                // A PURE-HOST viewer (acting company is the host, not the home,
                // and not a Super Admin) sees MINIMAL, project-level presence —
                // no worker identity. The home side (and a Super Admin) sees the
                // full cross-charge detail.
                $hostOnly = ! $isSuperAdmin
                    && $companyId === $d->host_company_id
                    && $companyId !== $d->home_company_id;

                $base = [
                    'id' => $d->id,
                    'viewer' => $hostOnly ? 'host' : 'home',
                    'home_company' => $d->homeCompany?->name,
                    'host_company' => $d->hostCompany?->name,
                    'project' => $d->project?->name,
                    'start' => $d->deployment_start->toDateString(),
                    'end' => $d->deployment_end?->toDateString(),
                    'days_present' => $summary['days'],
                    'billing_method' => $d->billing_method->value,
                    'status' => $d->status->value,
                    'settlement' => $settlement,
                    // The formal invoice: both sides may open the read-only PDF
                    // (served via the deployment, not the Invoices module).
                    'invoice' => [
                        'has' => $invoice !== null,
                        'number' => $invoice?->number,
                    ],
                ];

                if ($hostOnly) {
                    // Still no worker name. The host DOES see the amount they owe
                    // ONCE the deployment is completed (their own liability — the
                    // same figure already in their expense ledger) so they can
                    // settle it; while accruing, no amount is shown.
                    if ($isPayable) {
                        $base['settlement']['amount'] = (float) ($charge->amount ?? $summary['amount']);
                    }

                    return $base;
                }

                return $base + [
                    'employee' => $d->employee?->full_name,
                    'employee_id' => $d->employee_id,
                    'home_company_id' => $d->home_company_id,
                    // The TYPED rate (drives the edit form), kept distinct from
                    // the displayed exact-cost rate below.
                    'rate' => $d->rate_during_deployment,
                    'rate_type' => $d->rate_type->value,
                    'split_pct' => $d->split_pct,
                    'notes' => $d->notes,
                    'units' => $summary['units'],
                    // Live exact-cost the host owes the home company.
                    'accrued_cost' => $summary['amount'],
                    // The real exact-cost rate = amount ÷ units (mixed day types
                    // average out) — what the "RATE (exact cost)" label shows,
                    // NOT the typed rate_during_deployment.
                    'exact_rate' => $summary['units'] > 0
                        ? round($summary['amount'] / $summary['units'], 2)
                        : null,
                ];
            });

        return Inertia::render('Deployments/Index', [
            'deployments' => $deployments,
            'filters' => (object) $request->only(['status']),
            // Home-company options exclude the acting company (can't deploy from
            // yourself to yourself); host projects come from the tenant scope.
            'homeCompanies' => Company::query()
                ->when($companyId !== null, fn ($q) => $q->whereKeyNot($companyId))
                ->orderBy('name')->get(['id', 'name']),
            'projects' => Project::query()->active()->orderBy('name')->get(['id', 'name']),
            'rateTypes' => array_map(fn ($c) => $c->value, DeploymentRateType::cases()),
            'statuses' => array_map(fn ($c) => $c->value, DeploymentStatus::cases()),
            'can' => [
                'create' => Gate::allows('deployments.create'),
                'edit' => Gate::allows('deployments.edit'),
                'approve' => Gate::allows('deployments.approve'),
                // The UI additionally gates the Mark-as-paid control on
                // viewer === 'host' && settlement.is_payable && unpaid.
                'settle' => Gate::allows('deployments.edit'),
            ],
        ]);
    }

    /**
     * Employees available to deploy FROM a chosen home company. Crosses the
     * tenant scope deliberately (this is the cross-company feature) but is
     * gated by deployments.create and exposes only id + name.
     */
    public function availableEmployees(Request $request): JsonResponse
    {
        // Used by both the create and the edit modal (changing the deployed
        // worker), so either permission may reach it.
        abort_unless(Gate::allows('deployments.create') || Gate::allows('deployments.edit'), 403);

        $validated = $request->validate([
            'home_company_id' => ['required', 'integer'],
        ]);

        $homeCompanyId = (int) $validated['home_company_id'];
        $hostCompanyId = app(CurrentCompany::class)->id();

        // Cannot deploy from your own company to your own company
        abort_if($homeCompanyId === $hostCompanyId, 422, 'Home and host company must differ.');

        // Tenant scope only — SoftDeletes stays, so a removed employee is
        // never offered for a new posting.
        $employees = Employee::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $homeCompanyId)
            ->where('active', true)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'designation']);

        return response()->json($employees);
    }

    public function store(StoreDeploymentRequest $request): RedirectResponse
    {
        $hostCompanyId = app(CurrentCompany::class)->id();
        abort_if($hostCompanyId === null, 403);

        $data = $request->validated();

        // The project must belong to the host company (the acting company).
        $project = Project::query()->withoutGlobalScopes()->findOrFail($data['project_id']);
        abort_unless($project->company_id === $hostCompanyId, 404);

        // The employee must belong to the chosen HOME company (not the host).
        // Tenant scope only: a soft-deleted employee id smuggled into the
        // request must 404, not open a new posting.
        $employee = Employee::query()->withoutGlobalScope(CompanyScope::class)->findOrFail($data['employee_id']);
        abort_unless($employee->company_id === (int) $data['home_company_id'], 422);
        abort_if((int) $data['home_company_id'] === $hostCompanyId, 422, 'Home and host company must differ.');

        $this->assertNoOverlap($employee->id, $data['deployment_start'], $data['deployment_end'] ?? null);

        $deployment = new EmployeeDeployment($data);
        $deployment->host_company_id = $hostCompanyId;
        $deployment->approved_by = $request->user()?->id;
        $deployment->status = DeploymentStatus::Active;
        $deployment->save();

        return back()->with('success', __('ui.deployments.saved'));
    }

    /**
     * Edit an ACTIVE deployment. Only the fields that are safe to overwrite are
     * editable — employee, rate structure (type / €value / split %), end date,
     * notes. The deployment rate drives ONLY the host cross-charge (never the
     * worker's payroll, which pays the worker's own frozen wage), and the charge
     * is generated once on complete, so a simple overwrite is correct while the
     * posting is still active (no charge exists yet). Completed / cancelled
     * postings are frozen (their charge is already booked).
     */
    public function update(UpdateDeploymentRequest $request, EmployeeDeployment $deployment): RedirectResponse
    {
        $this->assertVisible($deployment);
        abort_unless($deployment->status === DeploymentStatus::Active, 422, __('ui.deployments.only_active_editable'));

        $data = $request->validated();
        $start = $deployment->deployment_start->toDateString();
        $newEnd = $data['deployment_end'] ?? null;
        $windowEnd = $deployment->deployment_end?->toDateString() ?? now()->toDateString();

        // Guard: never shorten the end date before a day already logged on the
        // host project (that would drop real worked days out of the charge).
        if ($newEnd !== null) {
            abort_if($newEnd < $start, 422, __('ui.deployments.end_before_start'));
            $lastLogged = Attendance::query()->withoutGlobalScopes()
                ->where('employee_id', $deployment->employee_id)
                ->where('project_id', $deployment->project_id)
                ->whereBetween('date', [$start, $windowEnd])
                ->max('date');
            if ($lastLogged !== null && $newEnd < $lastLogged) {
                throw ValidationException::withMessages(['deployment_end' => __('ui.deployments.end_before_attendance')]);
            }
        }

        // Guard: only allow changing the employee while no attendance has been
        // logged for the CURRENT worker on this posting — otherwise a swap would
        // misattribute real worked days. The new worker must belong to the same
        // (unchanged) home company, and must not overlap another active posting.
        $newEmployeeId = (int) $data['employee_id'];
        if ($newEmployeeId !== (int) $deployment->employee_id) {
            $hasLogged = Attendance::query()->withoutGlobalScopes()
                ->where('employee_id', $deployment->employee_id)
                ->where('project_id', $deployment->project_id)
                ->whereBetween('date', [$start, $windowEnd])
                ->exists();
            if ($hasLogged) {
                throw ValidationException::withMessages(['employee_id' => __('ui.deployments.employee_locked_by_attendance')]);
            }

            $newEmployee = Employee::query()->withoutGlobalScope(CompanyScope::class)->findOrFail($newEmployeeId);
            abort_unless($newEmployee->company_id === (int) $deployment->home_company_id, 422);
            $this->assertNoOverlap($newEmployeeId, $start, $newEnd, $deployment->id);
        }

        $deployment->update([
            'employee_id' => $newEmployeeId,
            'rate_type' => $data['rate_type'],
            'rate_during_deployment' => $data['rate_during_deployment'] ?? null,
            'split_pct' => $data['split_pct'] ?? 100,
            'deployment_end' => $newEnd,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', __('ui.deployments.updated'));
    }

    /**
     * Complete a deployment → generate the Option A cross-charge.
     */
    public function complete(Request $request, EmployeeDeployment $deployment, DeploymentChargeService $charges, NotificationDispatcher $notify): RedirectResponse
    {
        Gate::authorize('deployments.edit');
        $this->assertVisible($deployment);

        // Only an ACTIVE posting completes: completing twice would refresh the
        // cross-charge off a stale window, and completing a cancelled one
        // would charge the host for a posting that never ran.
        abort_unless($deployment->status === DeploymentStatus::Active, 422, 'Only an active deployment can be completed.');

        $deployment->update([
            'status' => DeploymentStatus::Completed,
            'deployment_end' => $deployment->deployment_end ?? now()->toDateString(),
        ]);

        $charges->generateCharge($deployment->fresh());
        $this->notifyDeploymentEvent($notify, $deployment, 'completed');

        return back()->with('success', __('ui.deployments.completed'));
    }

    public function cancel(Request $request, EmployeeDeployment $deployment, NotificationDispatcher $notify): RedirectResponse
    {
        Gate::authorize('deployments.edit');
        $this->assertVisible($deployment);

        // A completed posting has already produced its cross-charge and host
        // expense; cancelling it would leave that money orphaned on a posting
        // marked as never having run.
        abort_unless($deployment->status === DeploymentStatus::Active, 422, 'Only an active deployment can be cancelled.');

        $deployment->update(['status' => DeploymentStatus::Cancelled]);
        $this->notifyDeploymentEvent($notify, $deployment, 'cancelled');

        return back()->with('success', __('ui.deployments.cancelled'));
    }

    /**
     * Invoice-based settlement (2026-09). The HOST company (the debtor — it owes
     * the HOME company for the deployed labour) marks the cross-charge paid once
     * it has reimbursed the home company; it can also un-mark it (correction).
     *
     * ⚠️ P&L SAFETY (Item A): this writes ONLY the charge's settlement columns.
     * It NEVER sets Expense.approved and never runs through the cross-charge
     * engine, so the internal_deployment expense stays unapproved and the
     * project P&L (labour counted once via attendance) is completely untouched.
     * This is deliberately NOT the expense-approval flow.
     */
    public function settlement(Request $request, EmployeeDeployment $deployment): RedirectResponse
    {
        Gate::authorize('deployments.edit');
        $this->assertVisible($deployment);

        // Only the HOST (the payer) may settle — the home side is read-only.
        $companyId = app(CurrentCompany::class)->id();
        abort_unless($companyId !== null && $companyId === $deployment->host_company_id, 403);

        $validated = $request->validate(['paid' => ['required', 'boolean']]);

        $charge = DeploymentCharge::query()->where('employee_deployment_id', $deployment->id)->first();
        // Only a COMPLETED charge is payable: invoiced_at is stamped when the
        // deployment completes (the charge locks). An accruing one is not yet
        // settleable.
        abort_if($charge === null || $charge->invoiced_at === null, 422, __('ui.deployments.not_payable'));

        // Route through the SINGLE settlement writer so this control and the
        // host's Expense approval produce byte-identical state across all three
        // linked records (host expense.approved, home invoice paid, charge).
        app(DeploymentSettlementService::class)->settle($charge, $validated['paid'], $request->user()?->id);

        return back()->with('success', __('ui.deployments.'.($validated['paid'] ? 'marked_paid' : 'marked_unpaid')));
    }

    /**
     * The inter-company invoice PDF, served through the DEPLOYMENT (visibleTo
     * home OR host), NOT the Invoices module — so the HOST gets a read-only copy
     * of what it is billed WITHOUT breaking invoice tenancy (it never appears in
     * their own Invoices list). The document is worker-free by construction, so
     * the host learns no worker identity from it.
     */
    public function invoicePdf(EmployeeDeployment $deployment, AuditLogger $audit): HttpResponse
    {
        Gate::authorize('deployments.view');
        $this->assertVisible($deployment);

        $charge = DeploymentCharge::query()->where('employee_deployment_id', $deployment->id)->first();
        abort_if($charge === null, 404);

        $invoice = Invoice::query()->withoutGlobalScope(CompanyScope::class)
            ->with(['lineItems', 'counterpartyCompany', 'company'])
            ->where('deployment_charge_id', $charge->id)
            ->first();
        abort_if($invoice === null, 404);

        $audit->log('exported', $invoice, null, null, 'Deployment invoice PDF', 'deployments');

        $pdf = Pdf::loadView('exports.invoice-pdf', [
            'invoice' => $invoice,
            'logo' => null,
        ]);

        return $pdf->download('factura-'.$invoice->number.'.pdf');
    }

    /**
     * Reference implementation of a Phase-8 alert: a deployment lifecycle
     * event notifies both companies' admins through NotificationDispatcher,
     * which consults the Settings matrix for who actually receives it. The
     * home company owns the worker, so its id scopes the recipient list; the
     * Super Admin (cross-company by default) sees it either way.
     */
    private function notifyDeploymentEvent(NotificationDispatcher $notify, EmployeeDeployment $deployment, string $event): void
    {
        $deployment->loadMissing(['employee:id,full_name', 'homeCompany:id,name', 'hostCompany:id,name']);
        $name = $deployment->employee !== null ? $deployment->employee->full_name : '—';
        $home = $deployment->homeCompany !== null ? $deployment->homeCompany->name : '';
        $host = $deployment->hostCompany !== null ? $deployment->hostCompany->name : '';

        $notify->dispatch(NotificationType::DeploymentEvent, $deployment->home_company_id, [
            'title_es' => "Desplazamiento {$event}: {$name}",
            'title_en' => "Deployment {$event}: {$name}",
            'entity' => trim("{$home} → {$host}"),
            'company' => $home !== '' ? $home : null,
            'url' => '/deployments',
        ]);
    }

    /**
     * No double-deployment: an active deployment for the same employee whose
     * dates overlap the requested window is rejected (open-ended counts as
     * running to infinity). $exceptId skips the deployment being edited so an
     * update never conflicts with itself.
     */
    private function assertNoOverlap(int $employeeId, string $start, ?string $end, ?int $exceptId = null): void
    {
        $overlap = EmployeeDeployment::query()
            ->where('employee_id', $employeeId)
            ->where('status', DeploymentStatus::Active->value)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->where('deployment_start', '<=', $end ?? '9999-12-31')
            ->where(fn ($q) => $q->whereNull('deployment_end')->orWhere('deployment_end', '>=', $start))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'deployment_start' => __('ui.deployments.overlap'),
            ]);
        }
    }

    private function assertVisible(EmployeeDeployment $deployment): void
    {
        $companyId = app(CurrentCompany::class)->id();

        abort_unless(
            $companyId === null
                || $deployment->home_company_id === $companyId
                || $deployment->host_company_id === $companyId,
            404,
        );
    }
}
