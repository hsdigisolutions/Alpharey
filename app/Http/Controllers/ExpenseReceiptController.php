<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\Audit\AuditLogger;
use App\Services\Expenses\ExpenseReceiptExport;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expense receipt documents — inline preview + the two tax-filing exports (ZIP
 * of originals with an Excel/PDF index, and a combined review PDF). The receipt
 * set + scope handling live in ExpenseReceiptExport; this controller only gates,
 * audits, resolves the filters, and streams the result.
 */
class ExpenseReceiptController extends Controller
{
    public function __construct(private ExpenseReceiptExport $export) {}

    /**
     * Serve a receipt file INLINE for the preview panel (image in an <img>, PDF
     * in an <iframe>). Gated + audited; the route-model binding 404s a
     * cross-company id, so no foreign receipt is reachable.
     */
    public function preview(Expense $expense, AuditLogger $audit): HttpResponse|StreamedResponse
    {
        Gate::authorize('expenses.view');

        $path = $expense->getAttribute('file_path');
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        $audit->log('viewed', $expense, null, null, 'Expense receipt preview', 'expenses');

        // Inline (not an attachment) so the browser renders it in place.
        return Storage::disk('local')->response($path, $expense->original_name ?? 'recibo');
    }

    public function zip(Request $request, AuditLogger $audit): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('expenses.export');

        [$companyId, $filters, $isSa] = $this->resolve($request);
        $receipts = $this->export->query($companyId, $filters, $isSa);

        $path = $this->export->buildZip($receipts, $this->label($filters));
        if ($path === null) {
            return back()->with('warning', __('ui.expenses.receipts_none'));
        }

        $audit->log('exported', new Expense, null, null, 'Expense receipts ZIP ('.$receipts->count().')', 'expenses');

        return response()->download($path, basename($path))->deleteFileAfterSend(true);
    }

    public function pdf(Request $request, AuditLogger $audit): HttpResponse|RedirectResponse
    {
        Gate::authorize('expenses.export');

        [$companyId, $filters, $isSa] = $this->resolve($request);
        $receipts = $this->export->query($companyId, $filters, $isSa);

        $bytes = $this->export->buildCombinedPdf($receipts, $this->label($filters));
        if ($bytes === null) {
            return back()->with('warning', __('ui.expenses.receipts_none'));
        }

        $audit->log('exported', new Expense, null, null, 'Expense receipts combined PDF ('.$receipts->count().')', 'expenses');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="recibos-'.$this->label($filters).'.pdf"',
        ]);
    }

    /**
     * Resolve [companyId, filters, isSuperAdmin] from the request. Non-SA users
     * are never allowed the cross-company 'all' scope.
     *
     * @return array{0: ?int, 1: array{from: ?string, to: ?string, scope: string, project_id: ?int}, 2: bool}
     */
    private function resolve(Request $request): array
    {
        $user = $request->user();
        $isSa = $user !== null && $user->isSuperAdmin();

        $scope = (string) $request->query('scope', 'company');
        if ($scope === 'all' && ! $isSa) {
            $scope = 'company';
        }

        return [
            app(CurrentCompany::class)->id(),
            [
                'from' => $this->validDate($request->query('from')),
                'to' => $this->validDate($request->query('to')),
                'scope' => in_array($scope, ['company', 'all', 'project'], true) ? $scope : 'company',
                'project_id' => $request->filled('project_id') ? (int) $request->query('project_id') : null,
            ],
            $isSa,
        ];
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{from: ?string, to: ?string, scope: string, project_id: ?int}  $filters
     */
    private function label(array $filters): string
    {
        $from = $filters['from'] ?? 'inicio';
        $to = $filters['to'] ?? 'hoy';

        return $from.'_a_'.$to;
    }
}
