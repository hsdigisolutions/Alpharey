<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 02 — Welcome / Company Selector (Super Admin only).
 * Employee/project/compliance/deployment figures light up as their
 * modules land (Phases 2–5); until then they render as "—".
 */
class WelcomeController extends Controller
{
    public function index(): Response
    {
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
                // Placeholders until the owning phases land:
                'employees_count' => null,   // Phase 2
                'projects_count' => null,    // Phase 3
                'compliance' => null,        // Phase 2 (ok|warn|danger)
                'deployed_count' => null,    // Phase 5
            ]);

        return Inertia::render('Welcome', [
            'companies' => $companies,
            'stats' => [
                'total_employees' => null,
                'active_projects' => null,
                'docs_expiring' => null,
                'invoices_pending' => null,
                'active_deployments' => null,
            ],
        ]);
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
