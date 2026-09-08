<?php

namespace App\Services\Expenses;

use App\Exports\ExpenseReceiptIndexExport;
use App\Models\Expense;
use App\Models\Scopes\CompanyScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use ZipArchive;

/**
 * Builds the two expense-receipt export packages for tax filing:
 *   - a ZIP of the original receipt files + an Excel AND PDF reconciliation
 *     index (the primary, tax-correct deliverable — originals preserved, each
 *     row matching a receipt to its expense record);
 *   - a single combined PDF (cover index + every receipt rendered as pages) for
 *     quick internal review.
 *
 * The receipt set is every EXPENSE that carries a stored file (`file_path`),
 * filtered by date range and scope (one project / the company / all companies —
 * the last for a Super Admin only). Files live on the PRIVATE disk.
 */
class ExpenseReceiptExport
{
    /** Safety cap so a huge period can't exhaust memory building a combined PDF. */
    private const MAX_COMBINED = 300;

    /**
     * The receipt rows for the on-screen list + both exports.
     *
     * @param  array{from?: ?string, to?: ?string, scope?: ?string, project_id?: ?int}  $filters
     * @return Collection<int, Expense>
     */
    public function query(?int $companyId, array $filters, bool $isSuperAdmin): Collection
    {
        $scope = $filters['scope'] ?? 'company';
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $projectId = $filters['project_id'] ?? null;

        $q = Expense::query()
            ->whereNotNull('file_path')
            ->with(['vendor:id,name', 'project:id,name,company_id', 'category:id,name'])
            ->when($from !== null, fn ($qq) => $qq->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($qq) => $qq->whereDate('date', '<=', $to))
            ->orderBy('date')
            ->orderBy('id');

        // All companies is a Super-Admin-only cross-tenant read; every other
        // scope stays inside one company.
        if ($scope === 'all' && $isSuperAdmin) {
            $q->withoutGlobalScope(CompanyScope::class);
        } elseif ($scope === 'project' && $projectId !== null) {
            $q->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->where('project_id', $projectId);
        } else {
            // Company scope: the global scope already limits to the acting
            // company, but pin it explicitly for a Super Admin with a selection.
            $q->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId);
        }

        return $q->get();
    }

    /**
     * A flat, display-ready row for one receipt (list payload + index sheets).
     *
     * @return array<string, mixed>
     */
    public function row(Expense $e): array
    {
        $ext = strtolower(pathinfo((string) ($e->original_name ?: $e->file_path), PATHINFO_EXTENSION));

        return [
            'id' => $e->id,
            'date' => $e->date->toDateString(),
            'number' => $e->number,
            'vendor' => $e->vendor?->name,
            'project' => $e->project?->name,
            'category' => $e->category?->name,
            'concept' => $e->notes,
            'base' => (float) $e->subtotal,
            'vat' => (float) $e->vat_amount,
            'total' => (float) $e->total,
            'payment_method' => $e->payment_method?->value,
            'filename' => $this->fileName($e, $ext),
            'original_name' => $e->original_name,
            'ext' => $ext,
            'is_image' => in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true),
            'is_pdf' => $ext === 'pdf',
        ];
    }

    /**
     * The tax-convention entry name: YYYY-MM-DD__vendor__€total__expNNN.ext
     * (expNNN = the factura number when present, else the record id).
     */
    public function fileName(Expense $e, string $ext): string
    {
        $vendorName = $e->vendor?->name;
        $vendor = Str::of((string) ($vendorName ?? 'sin-proveedor'))
            ->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-')->limit(40, '')->value();
        $vendor = $vendor !== '' ? $vendor : 'sin-proveedor';
        $ref = $e->number ? Str::of($e->number)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-')->value() : ('exp'.$e->id);
        $total = number_format((float) $e->total, 2, '.', '');

        return "{$e->date->toDateString()}__{$vendor}__€{$total}__{$ref}".($ext !== '' ? ".{$ext}" : '');
    }

    /**
     * Build the ZIP package to a temp path (caller streams it with
     * deleteFileAfterSend). Returns null when there are no receipts.
     *
     * @param  Collection<int, Expense>  $receipts
     */
    public function buildZip(Collection $receipts, string $label): ?string
    {
        if ($receipts->isEmpty()) {
            return null;
        }

        $rows = $receipts->map(fn (Expense $e): array => $this->row($e))->all();

        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $zipPath = $dir.'/receipts-'.$label.'-'.Str::random(8).'.zip';

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        // The original receipt files (private disk), renamed to the convention.
        $used = [];
        foreach ($receipts as $e) {
            $path = $e->getAttribute('file_path');
            if ($path === null || ! Storage::disk('local')->exists($path)) {
                continue;
            }
            $ext = strtolower(pathinfo((string) ($e->original_name ?: $path), PATHINFO_EXTENSION));
            $name = $this->fileName($e, $ext);
            // Guard against a duplicate entry name (same vendor/date/total).
            $n = $used[$name] ?? 0;
            $used[$name] = $n + 1;
            if ($n > 0) {
                $name = preg_replace('/(\.[^.]+)$/', "-{$n}$1", $name) ?? $name;
            }
            $zip->addFile(Storage::disk('local')->path($path), 'recibos/'.$name);
        }

        // Reconciliation index — Excel AND PDF, both inside the zip.
        $zip->addFromString('index.xlsx', Excel::raw(new ExpenseReceiptIndexExport($rows), ExcelWriter::XLSX));
        $zip->addFromString('index.pdf', Pdf::loadView('exports.expense-receipts-index-pdf', [
            'rows' => $rows,
            'label' => $label,
        ])->output());

        $zip->close();

        return $zipPath;
    }

    /**
     * Build the combined review PDF (bytes). Cover index page + each receipt
     * rendered as image page(s). PDFs are rasterised via Imagick/Ghostscript;
     * images embed directly. Returns null when there are no receipts.
     *
     * @param  Collection<int, Expense>  $receipts
     */
    public function buildCombinedPdf(Collection $receipts, string $label): ?string
    {
        if ($receipts->isEmpty()) {
            return null;
        }

        $rows = $receipts->map(fn (Expense $e): array => $this->row($e))->all();

        $items = [];
        $count = 0;
        foreach ($receipts as $e) {
            if ($count >= self::MAX_COMBINED) {
                break;
            }
            $path = $e->getAttribute('file_path');
            if ($path === null || ! Storage::disk('local')->exists($path)) {
                continue;
            }
            $count++;
            $ext = strtolower(pathinfo((string) ($e->original_name ?: $path), PATHINFO_EXTENSION));
            $abs = Storage::disk('local')->path($path);
            $images = $this->toImages($abs, $ext);

            $vendorName = $e->vendor?->name;
            $items[] = [
                'title' => $this->fileName($e, $ext),
                'meta' => trim(($vendorName ?? '—').' · '.$e->date->toDateString().' · €'.number_format((float) $e->total, 2, '.', '')),
                'images' => $images,
                'unrenderable' => $images === [],
            ];
        }

        return Pdf::loadView('exports.expense-receipts-combined-pdf', [
            'rows' => $rows,
            'items' => $items,
            'label' => $label,
            'capped' => $receipts->count() > self::MAX_COMBINED,
            'max' => self::MAX_COMBINED,
        ])->output();
    }

    /**
     * Rasterise a receipt file into base64 data-URI page images for embedding.
     * Images pass through (re-encoded); PDFs go through Imagick (Ghostscript).
     * Returns [] when the file cannot be rendered (kept in the ZIP regardless).
     *
     * @return list<string>
     */
    private function toImages(string $absPath, string $ext): array
    {
        try {
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $bytes = @file_get_contents($absPath);
                if ($bytes === false) {
                    return [];
                }

                return ['data:image/'.($ext === 'jpg' ? 'jpeg' : $ext).';base64,'.base64_encode($bytes)];
            }

            if ($ext === 'pdf' && class_exists(\Imagick::class)) {
                $im = new \Imagick;
                $im->setResolution(150, 150);
                $im->readImage($absPath);
                $pages = [];
                foreach ($im as $page) {
                    $page->setImageFormat('jpeg');
                    $page->setImageCompressionQuality(75);
                    $page->setImageBackgroundColor('white');
                    $flat = $page->flattenImages();
                    $pages[] = 'data:image/jpeg;base64,'.base64_encode($flat->getImageBlob());
                    if (count($pages) >= 20) { // per-file page guard
                        break;
                    }
                }
                $im->clear();

                return $pages;
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }
}
