<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentRateType;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationType;
use App\Http\Requests\Deployments\StoreDeploymentRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Project;
use App\Services\Deployments\DeploymentChargeService;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $deployments = EmployeeDeployment::query()
            ->visibleTo($companyId)
            ->with(['employee:id,full_name', 'homeCompany:id,name', 'hostCompany:id,name', 'project:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('deployment_start')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (EmployeeDeployment $d): array => [
                'id' => $d->id,
                'employee' => $d->employee?->full_name,
                'home_company' => $d->homeCompany?->name,
                'host_company' => $d->hostCompany?->name,
                'project' => $d->project?->name,
                'start' => $d->deployment_start->toDateString(),
                'end' => $d->deployment_end?->toDateString(),
                'rate' => $d->rate_during_deployment,
                'rate_type' => $d->rate_type->value,
                'billing_method' => $d->billing_method->value,
                'status' => $d->status->value,
                'accrued_cost' => Gate::allows('payroll.view') || Gate::allows('deployments.approve')
                    ? $charges->accruedAmount($d) : null,
            ]);

        return Inertia::render('Deployments/Index', [
            'deployments' => $deployments,
            'filters' => $request->only(['status']),
            // Home-company options exclude the acting company (can't deploy from
            // yourself to yourself); host projects come from the tenant scope.
            'homeCompanies' => Company::query()
                ->when($companyId !== null, fn ($q) => $q->whereKeyNot($companyId))
                ->orderBy('name')->get(['id', 'name']),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'rateTypes' => array_map(fn ($c) => $c->value, DeploymentRateType::cases()),
            'statuses' => array_map(fn ($c) => $c->value, DeploymentStatus::cases()),
            'can' => [
                'create' => Gate::allows('deployments.create'),
                'edit' => Gate::allows('deployments.edit'),
                'approve' => Gate::allows('deployments.approve'),
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
        Gate::authorize('deployments.create');

        $validated = $request->validate([
            'home_company_id' => ['required', 'integer'],
        ]);

        $homeCompanyId = (int) $validated['home_company_id'];
        $hostCompanyId = app(CurrentCompany::class)->id();

        // Cannot deploy from your own company to your own company
        abort_if($homeCompanyId === $hostCompanyId, 422, 'Home and host company must differ.');

        $employees = Employee::query()
            ->withoutGlobalScopes()
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
        $employee = Employee::query()->withoutGlobalScopes()->findOrFail($data['employee_id']);
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
     * Complete a deployment → generate the Option A cross-charge.
     */
    public function complete(Request $request, EmployeeDeployment $deployment, DeploymentChargeService $charges, NotificationDispatcher $notify): RedirectResponse
    {
        Gate::authorize('deployments.edit');
        $this->assertVisible($deployment);

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

        $deployment->update(['status' => DeploymentStatus::Cancelled]);
        $this->notifyDeploymentEvent($notify, $deployment, 'cancelled');

        return back()->with('success', __('ui.deployments.cancelled'));
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
     * running to infinity).
     */
    private function assertNoOverlap(int $employeeId, string $start, ?string $end): void
    {
        $overlap = EmployeeDeployment::query()
            ->where('employee_id', $employeeId)
            ->where('status', DeploymentStatus::Active->value)
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
