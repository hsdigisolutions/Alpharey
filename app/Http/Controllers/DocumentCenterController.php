<?php

namespace App\Http\Controllers;

use App\Exports\DocumentCenterExport;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentCenterService;
use App\Support\CurrentCompany;
use App\Support\DocumentPanelPayload;
use App\Support\DocumentTypes;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The Global Document Command Center (/documents) — one hub for every tracked
 * document across companies, employees, projects, clients, vendors and
 * vehicles. Five views over a single cached dataset (DocumentCenterService):
 * Urgent, All, By Entity, Timeline, Compliance Dashboard. Missing docs are
 * computed, not stored. Super Admin sees all companies; everyone else their own.
 */
class DocumentCenterController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request, DocumentCenterService $center): Response
    {
        Gate::authorize('documents.view');

        $data = $center->dataset();
        $rows = $data['rows'];

        $filters = $this->filters($request);
        $filtered = $center->filter($rows, $filters);

        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) $request->integer('page', 1)), $lastPage);

        return Inertia::render('Documents/Index', [
            'summary' => $data['summary'],
            'urgent' => $center->urgent($rows),
            'byEntity' => $center->byEntity($rows),
            'timeline' => $center->timeline($rows),
            'dashboard' => $center->dashboard($rows),
            'allPage' => [
                'data' => array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
                'total' => $total,
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => self::PER_PAGE,
            ],
            'filters' => $filters,
            'fieldDefs' => [
                'company' => DocumentTypes::fieldDefsMap('company'),
                'employee' => DocumentTypes::fieldDefsMap('employee'),
                'project' => DocumentTypes::fieldDefsMap('project'),
                'client' => DocumentTypes::fieldDefsMap('client'),
                'vendor' => DocumentTypes::fieldDefsMap('vendor'),
            ],
            'companyOptions' => $this->companyOptions(),
            'entityTypes' => ['company', 'employee', 'project', 'client', 'vendor', 'vehicle'],
            'statuses' => ['ok', 'warn', 'danger', 'neutral', 'missing', 'exempt'],
            'can' => [
                'view' => Gate::allows('documents.view'),
                'upload' => Gate::allows('documents.upload'),
                'download' => Gate::allows('documents.download'),
                'editDocs' => Gate::allows('documents.edit'),
                'deleteDocs' => Gate::allows('documents.delete'),
                'exempt' => Gate::allows('documents.approve'),
                'export' => Gate::allows('documents.export'),
            ],
        ]);
    }

    /**
     * On-demand panel payload for one document (inline View).
     */
    public function panel(Document $document, DocumentPanelPayload $panel, DocumentController $documents): JsonResponse
    {
        Gate::authorize('documents.view');
        $documents->assertCompanyDocumentAccess($document); // company docs are admin-only

        return response()->json(['doc' => $panel->single($document)]);
    }

    /**
     * Bulk mark/unmark exempt — real documents only.
     */
    public function bulkExempt(Request $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('documents.approve');

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['string'],
            'exempt' => ['required', 'boolean'],
        ]);

        // The global CompanyScope applies here: only documents the user's
        // company can see are returned; foreign ids are silently dropped.
        $documents = Document::query()->whereIn('id', $validated['ids'])->get();

        $user = $request->user();
        $isAdmin = $user !== null && ($user->isSuperAdmin() || $user->isCompanyAdmin());

        foreach ($documents as $document) {
            // Company official records stay admin-only, even in a bulk action.
            if ($document->documentable_type === Company::class && ! $isAdmin) {
                continue;
            }
            $document->update(['is_exempt' => $validated['exempt']]);
            $audit->log('updated', $document, null, null, $document->type_key, 'documents');
        }

        return back()->with('success', __('ui.documents.saved'));
    }

    /**
     * Export the filtered view (Excel or PDF), gated + audited.
     */
    public function export(Request $request, DocumentCenterService $center, AuditLogger $audit): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('documents.export');

        $rows = $center->filter($center->dataset()['rows'], $this->filters($request));
        $format = $request->string('format')->value() === 'pdf' ? 'pdf' : 'excel';

        $audit->log('exported', new Document, null, null, 'document-center-'.$format, 'documents');

        $export = new DocumentCenterExport($rows);

        if ($format === 'pdf') {
            /** @var \Barryvdh\DomPDF\PDF $pdf */
            $pdf = Pdf::loadView('exports.document-center-pdf', ['rows' => $export->displayRows()]);

            return $pdf->download('document-center.pdf');
        }

        return Excel::download($export, 'document-center.xlsx');
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->string('search')->value(),
            'category' => $request->string('category')->value(),
            'type_key' => $request->string('type_key')->value(),
            'status' => $request->string('status')->value(),
            'company_id' => $request->string('company_id')->value(),
            'from' => $request->string('from')->value(),
            'to' => $request->string('to')->value(),
        ];
    }

    /**
     * Companies for the filter — Super Admin only (everyone else is single-company).
     *
     * @return list<array{id: int, name: string}>
     */
    private function companyOptions(): array
    {
        $user = request()->user();

        if (! $user instanceof User || ! $user->isSuperAdmin() || app(CurrentCompany::class)->id() !== null) {
            return [];
        }

        return Company::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Company $c): array => ['id' => $c->id, 'name' => $c->name])->all();
    }
}
