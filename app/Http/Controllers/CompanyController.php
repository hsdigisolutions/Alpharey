<?php

namespace App\Http\Controllers;

use App\Http\Requests\Companies\DestroyCompanyRequest;
use App\Http\Requests\Companies\StoreCompanyRequest;
use App\Http\Requests\Companies\UpdateCompanyRequest;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
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
        // Employee counts per company — tenant scope only (SA operates across
        // companies); SoftDeletes stays so removed employees don't inflate it.
        $employeeCounts = Employee::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('active', true)
            ->selectRaw('company_id, count(*) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        return Inertia::render('Companies/Index', [
            'companies' => Company::query()
                ->withCount('users')
                ->with(['documents' => fn ($q) => $q->with('uploader:id,name')->orderByDesc('version')])
                ->orderBy('name')
                ->get()
                ->map(function (Company $company) use ($status, $employeeCounts): array {
                    // One eager load carries both the live docs and their history;
                    // split here so the panel gets current rows + prior versions.
                    $current = $company->documents->where('is_current', true)->values();
                    $history = $company->documents->where('is_current', false)->groupBy('type_key');

                    return [
                        'id' => $company->id,
                        'name' => $company->name,
                        'cif' => $company->cif,
                        'ccc' => $company->ccc,
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
                        'compliance_score' => $this->complianceScore($current, $status),
                        'documents' => $current
                            ->map(fn (Document $document): array => $this->documentRow(
                                $document,
                                $status,
                                $company,
                                $history->get($document->type_key, collect()),
                            ))
                            ->values(),
                    ];
                }),
            'companyDocTypes' => DocumentTypes::company(),
            'companyFieldDefs' => DocumentTypes::companyFields(),
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
     * @param  Collection<int, Document>  $history  prior versions of this slot
     * @return array<string, mixed>
     */
    private function documentRow(Document $document, DocumentStatus $status, Company $company, Collection $history): array
    {
        [$state, $daysLeft] = $status->of($document);

        $config = DocumentTypes::companyFields()[$document->type_key] ?? null;
        $fieldDefs = $config['fields'] ?? [];

        // 2E — CCC is read-only from the company; inject it whenever the type
        // declares a ccc field so the panel never asks the admin to type it.
        $hasCcc = collect($fieldDefs)->contains(fn (array $f): bool => $f['type'] === 'ccc');

        return [
            'id' => $document->id,
            'category' => $document->category,
            'type_key' => $document->type_key,
            'name' => $document->name,
            'original_name' => $document->original_name,
            'has_file' => $document->getAttribute('file_path') !== null,
            'has_flag' => $document->has_flag,
            'issue_date' => $document->issue_date?->toDateString(),
            'expiry_date' => $document->expiry_date?->toDateString(),
            'notes' => $document->notes,
            'version' => $document->version,
            'status' => $state,
            'days_left' => $daysLeft,
            'uploaded_at' => $document->created_at?->toDateString(),
            'uploaded_by' => $document->uploader?->name,
            // Smart-panel payload
            'metadata' => $document->metadata ?? [],
            'contacts' => $document->contacts ?? [],
            'field_defs' => $fieldDefs,
            'ccc' => $hasCcc ? $company->ccc : null,
            'history' => $history
                ->sortByDesc('version')
                ->map(fn (Document $version): array => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'uploaded_at' => $version->created_at?->toDateString(),
                    'uploaded_by' => $version->uploader?->name,
                    'issue_date' => $version->issue_date?->toDateString(),
                    'expiry_date' => $version->expiry_date?->toDateString(),
                    'has_file' => $version->getAttribute('file_path') !== null,
                ])
                ->values()
                ->all(),
        ];
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $brand = Brand::query()->firstOrCreate(['name' => 'AlphaRey']);

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
