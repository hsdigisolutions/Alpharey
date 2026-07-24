<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Services\Documents\DocumentStatus;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 02 — Welcome / Company Selector (Super Admin only).
 *
 * The Super Admin browses every company at once, so each figure is computed
 * across ALL companies with the tenant scope dropped and then keyed back by
 * company_id — never inside a single-company context. Cheap grouped counts
 * (one query per figure), fine for an occasional SA landing screen.
 */
class WelcomeController extends Controller
{
    public function __construct(private readonly DocumentStatus $documentStatus) {}

    public function index(): Response
    {
        $today = Carbon::now()->toDateString();
        $horizon = Carbon::now()->addDays($this->documentStatus->warnDays())->toDateString();

        $employees = $this->countByCompany(
            Employee::query()->withoutGlobalScope(CompanyScope::class)->where('active', true),
        );
        $projects = $this->countByCompany(
            Project::query()->withoutGlobalScope(CompanyScope::class)
                ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::InProgress->value]),
        );

        // Deployed OUT: this company's employees currently posted elsewhere.
        // Deployments carry no tenant scope; home_company_id is the owner.
        $deployed = EmployeeDeployment::query()
            ->where('status', DeploymentStatus::Active->value)
            ->where('deployment_start', '<=', $today)
            ->where(fn ($q) => $q->whereNull('deployment_end')->orWhere('deployment_end', '>=', $today))
            ->selectRaw('home_company_id, COUNT(DISTINCT employee_id) as aggregate')
            ->groupBy('home_company_id')
            ->pluck('aggregate', 'home_company_id');

        // Compliance rollup per company from its own documents (all entity types
        // share the company_id via BelongsToCompany): worst wins.
        $currentDocs = Document::query()->withoutGlobalScope(CompanyScope::class)
            ->where('is_current', true)->where('is_exempt', false);
        $docsExpired = $this->countByCompany(
            (clone $currentDocs)->whereNotNull('expiry_date')->whereDate('expiry_date', '<', $today),
        );
        $docsExpiring = $this->countByCompany(
            (clone $currentDocs)->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', $today)->whereDate('expiry_date', '<=', $horizon),
        );
        $docsAny = $this->countByCompany(clone $currentDocs);

        $companies = Company::query()
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company): array => [
                'id' => $company->id,
                'name' => $company->name,
                'province' => $company->province,
                'cif' => $company->cif,
                'status' => $company->status,
                'users_count' => $company->users_count,
                'employees_count' => (int) ($employees[$company->id] ?? 0),
                'projects_count' => (int) ($projects[$company->id] ?? 0),
                'deployed_count' => (int) ($deployed[$company->id] ?? 0),
                'compliance' => $this->compliance(
                    (int) ($docsExpired[$company->id] ?? 0),
                    (int) ($docsExpiring[$company->id] ?? 0),
                    (int) ($docsAny[$company->id] ?? 0),
                ),
            ]);

        return Inertia::render('Welcome', [
            'companies' => $companies,
            'stats' => [
                'total_employees' => Employee::query()->withoutGlobalScope(CompanyScope::class)
                    ->where('active', true)->count(),
                'active_projects' => Project::query()->withoutGlobalScope(CompanyScope::class)
                    ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::InProgress->value])->count(),
                'docs_expiring' => (clone $currentDocs)->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<=', $horizon)->count(),
                'invoices_pending' => Invoice::query()->withoutGlobalScope(CompanyScope::class)
                    ->where('type', InvoiceType::Sale->value)
                    ->whereIn('payment_status', [
                        PaymentStatus::Unpaid->value, PaymentStatus::Partial->value, PaymentStatus::Pending->value,
                    ])->count(),
                'active_deployments' => EmployeeDeployment::query()
                    ->where('status', DeploymentStatus::Active->value)
                    ->where('deployment_start', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('deployment_end')->orWhere('deployment_end', '>=', $today))
                    ->count(),
            ],
        ]);
    }

    /**
     * COUNT(*) grouped by company_id, keyed by id for O(1) lookup per card.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Collection<int, int>
     */
    private function countByCompany(Builder $query): Collection
    {
        return $query->selectRaw('company_id, COUNT(*) as aggregate')
            ->groupBy('company_id')
            ->pluck('aggregate', 'company_id');
    }

    /** Worst-wins document traffic light for one company. */
    private function compliance(int $expired, int $expiring, int $any): string
    {
        return match (true) {
            $expired > 0 => 'danger',
            $expiring > 0 => 'warn',
            $any > 0 => 'ok',
            default => 'neutral',
        };
    }

    public function select(Company $company, CurrentCompany $currentCompany): RedirectResponse
    {
        $currentCompany->select($company);

        return redirect()->route('dashboard');
    }

    public function clearSelection(CurrentCompany $currentCompany): RedirectResponse
    {
        $currentCompany->clearSelection();

        return redirect()->route('welcome');
    }
}
