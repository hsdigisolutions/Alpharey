<?php

namespace App\Http\Controllers;

use App\Http\Requests\Companies\DestroyCompanyRequest;
use App\Http\Requests\Companies\StoreCompanyRequest;
use App\Http\Requests\Companies\UpdateCompanyRequest;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Services\Companies\CompanyRemovalGuard;
use App\Services\Documents\DocumentStatus;
use App\Support\CurrentCompany;
use App\Support\DocumentTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 04 — Companies (Super Admin only; route group enforces it).
 * Detail opens in a slide-over on the same page — never a separate page.
 */
class CompanyController extends Controller
{
    public function index(DocumentStatus $status): Response
    {
        // Employee counts per company, unscoped (SA operates across companies)
        $employeeCounts = Employee::query()
            ->withoutGlobalScopes()
            ->where('active', true)
            ->selectRaw('company_id, count(*) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        return Inertia::render('Companies/Index', [
            'companies' => Company::query()
                ->withCount('users')
                ->with(['documents' => fn ($q) => $q->where('is_current', true)])
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
                    'employees_count' => (int) ($employeeCounts[$company->id] ?? 0),
                    'projects_count' => null, // Phase 3
                    'compliance_score' => $this->complianceScore($company->documents, $status),
                    'documents' => $company->documents->map(fn ($document): array => $this->documentRow($document, $status))->values(),
                ]),
            'companyDocTypes' => DocumentTypes::company(),
        ]);
    }

    /**
     * @param  Collection<int, Document>  $documents
     */
    private function complianceScore($documents, DocumentStatus $status): ?int
    {
        $scores = $documents->map(fn ($document) => $status->score($document))->filter(fn ($s) => $s !== null);

        return $scores->isEmpty() ? null : (int) round($scores->avg() * 100);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRow(Document $document, DocumentStatus $status): array
    {
        [$state, $daysLeft] = $status->of($document);

        return [
            'id' => $document->id,
            'category' => $document->category,
            'type_key' => $document->type_key,
            'name' => $document->name,
            'original_name' => $document->original_name,
            'has_file' => $document->getAttribute('file_path') !== null,
            'has_flag' => $document->has_flag,
            'expiry_date' => $document->expiry_date?->toDateString(),
            'version' => $document->version,
            'status' => $state,
            'days_left' => $daysLeft,
        ];
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
