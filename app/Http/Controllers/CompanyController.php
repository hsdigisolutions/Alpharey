<?php

namespace App\Http\Controllers;

use App\Http\Requests\Companies\DestroyCompanyRequest;
use App\Http\Requests\Companies\StoreCompanyRequest;
use App\Http\Requests\Companies\UpdateCompanyRequest;
use App\Models\Brand;
use App\Models\Company;
use App\Services\Companies\CompanyRemovalGuard;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 04 — Companies (Super Admin only; route group enforces it).
 * Detail opens in a slide-over on the same page — never a separate page.
 */
class CompanyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Companies/Index', [
            'companies' => Company::query()
                ->withCount('users')
                ->orderBy('name')
                ->get()
                ->map(fn (Company $company): array => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'cif' => $company->cif,
                    'province' => $company->province,
                    'address' => $company->address,
                    'city' => $company->city,
                    'postal_code' => $company->postal_code,
                    'phone' => $company->phone,
                    'email' => $company->email,
                    'website' => $company->website,
                    'status' => $company->status,
                    'notes' => $company->notes,
                    'users_count' => $company->users_count,
                    // Phase 2+ placeholders for the Estadísticas tab
                    'employees_count' => null,
                    'projects_count' => null,
                    'compliance_score' => null,
                ]),
        ]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $brand = Brand::query()->firstOrCreate(['name' => 'Verto5']);

        Company::query()->create($request->validated() + ['brand_id' => $brand->id]);

        return back()->with('success', __('ui.companies.saved'));
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        $company->update($request->validated());

        return back()->with('success', __('ui.companies.saved'));
    }

    /**
     * Soft delete with safety checks + typed-name confirmation.
     */
    public function destroy(
        DestroyCompanyRequest $request,
        Company $company,
        CompanyRemovalGuard $guard,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        if ($request->string('confirm_name')->value() !== $company->name) {
            throw ValidationException::withMessages([
                'confirm_name' => __('ui.companies.confirm_mismatch'),
            ]);
        }

        $blockers = $guard->blockers($company);

        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'confirm_name' => collect($blockers)->map(fn (string $key) => __('ui.'.$key))->implode(' '),
            ]);
        }

        if ($currentCompany->id() === $company->id) {
            $currentCompany->clearSelection();
        }

        $company->delete();

        return back()->with('success', __('ui.companies.deleted'));
    }
}
