<?php

namespace App\Services\LegacyImport\Importers;

use App\Enums\VatRate;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Services\LegacyImport\AbstractImporter;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy invoices → invoices + invoice_line_items (DATA_MIGRATION.md §3.4/§3.5).
 *
 * Financial history migrates EXACTLY AS STORED — the importer never recomputes a
 * total from the line items and never applies the new optional-VAT rule to an old
 * row. That is the whole point: the books must still reconcile after the move.
 *
 * Two legacy quirks are handled per the documented precedence:
 *  - `total_amount` wins over `total`; `vat_percent` wins over `vat`. The
 *    duplicate is used only when the canonical is null/zero and the duplicate
 *    is not. Where the two disagree by more than rounding the row is still
 *    imported (canonical wins) but reported with BOTH values for a human.
 *  - the new schema stores a VatRate enum, the old one a raw percent. Official
 *    rates map across; anything else keeps its vat_amount but lands in the
 *    exceptions report rather than being rounded into a rate it never had.
 */
class InvoicesImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'invoices';
    }

    protected function import(): void
    {
        $defaultCompanyId = Company::query()->orderBy('id')->value('id');

        if ($defaultCompanyId === null) {
            $this->exception('invoices', null, 'No companies exist — run the seeder first.');

            return;
        }

        $this->legacy('invoices')->orderBy('id')->chunk(500, function ($rows) use ($defaultCompanyId): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                $total = $this->canonical($row, 'total_amount', 'total', 'invoices', $row->id);
                $vatPercent = $this->canonical($row, 'vat_percent', 'vat', 'invoices', $row->id);

                $vatRate = VatRate::fromPercent($vatPercent);

                // A percentage the official dropdown cannot express: keep the
                // money, flag the label. Never round it into a nearby rate.
                if ($vatPercent !== null && (float) $vatPercent > 0 && $vatRate === null) {
                    $this->exception('invoices', $row->id, 'VAT percentage is not an official rate — vat_rate left blank, vat_amount preserved', [
                        'vat_percent' => $vatPercent,
                    ]);
                }

                $invoice = new Invoice([
                    'number' => (string) ($row->number ?? $row->invoice_number ?? $row->id),
                    'type' => $this->normalizeType($row->type ?? null),
                    'sub_type' => ($row->invoice_type ?? null) === 'pre' ? 'pre' : 'final',
                    'client_id' => $row->client_id !== null ? $this->newIdFor($row->client_id, 'clients') : null,
                    'vendor_id' => $row->vendor_id !== null ? $this->newIdFor($row->vendor_id, 'vendors') : null,
                    'project_id' => $row->project_id !== null ? $this->newIdFor($row->project_id, 'projects') : null,
                    'invoice_date' => $row->invoice_date ?? $row->date ?? null,
                    'due_date' => $row->due_date ?? null,
                    'billing_type' => $row->billing_type ?? null,
                    'billing_period' => $row->billing_period ?? null,
                    // stored verbatim — never recomputed
                    'subtotal' => (string) ($row->subtotal ?? 0),
                    'vat_rate' => $vatRate?->value,
                    'vat_amount' => (string) ($row->vat_amount ?? 0),
                    'discount_type' => $row->discount_type ?? null,
                    'discount_value' => (string) ($row->discount_value ?? 0),
                    'discount_amount' => (string) ($row->discount_amount ?? 0),
                    'retention_percent' => $row->retention_percent ?? null,
                    'retention_amount' => (string) ($row->retention_amount ?? 0),
                    'total' => (string) ($total ?? 0),
                    'paid_amount' => (string) ($row->paid_amount ?? 0),
                    'status' => $this->normalizeStatus($row->status ?? null),
                    'payment_status' => $this->normalizePaymentStatus($row->payment_status ?? null),
                    'payment_date' => $row->payment_date ?? null,
                    'payment_method' => $row->payment_method ?? null,
                    'notes' => $row->notes ?? null,
                ]);
                $invoice->company_id = $defaultCompanyId;
                $invoice->save();

                $this->importLines($invoice, $row->id);

                $this->recordMapping($row->id, $invoice->id);
                $this->imported++;
            }
        });
    }

    private function importLines(Invoice $invoice, string|int $legacyInvoiceId): void
    {
        if (! $this->legacyHasTable('invoice_line_items')) {
            return;
        }

        $lines = $this->legacy('invoice_line_items')->where('invoice_id', $legacyInvoiceId)->orderBy('id')->get();

        foreach ($lines as $i => $line) {
            $item = new InvoiceLineItem([
                'description' => (string) ($line->description ?? ''),
                'quantity' => (string) ($line->quantity ?? 1),
                'unit_price' => (string) ($line->unit_price ?? 0),
                // verbatim: do not recompute qty x price, the stored figure is the fact
                'line_total' => (string) ($line->line_total ?? $line->total ?? 0),
                'sort_order' => $i,
            ]);
            $item->invoice_id = $invoice->id;
            $item->save();
        }
    }

    /**
     * Canonical-wins-over-duplicate, per DATA_MIGRATION.md §3.4. Disagreement
     * beyond rounding is reported with both values — resolved by a human.
     */
    private function canonical(object $row, string $canonical, string $duplicate, string $table, string|int $id): float|string|null
    {
        $a = $row->{$canonical} ?? null;
        $b = $row->{$duplicate} ?? null;

        if ($a !== null && $b !== null && abs((float) $a - (float) $b) > 0.01) {
            $this->exception($table, $id, "Conflicting {$canonical}/{$duplicate} — {$canonical} used", [
                $canonical => $a,
                $duplicate => $b,
            ]);
        }

        // fall back to the duplicate only when the canonical is null/zero and it is not
        if (($a === null || (float) $a == 0.0) && $b !== null && (float) $b != 0.0) {
            return $b;
        }

        return $a;
    }

    /**
     * Line items are optional: some legacy invoices are a single total with no
     * breakdown, and the table may not exist at all in older dumps.
     */
    private function legacyHasTable(string $table): bool
    {
        return Schema::connection('legacy')->hasTable($table);
    }

    private function normalizeType(?string $legacy): string
    {
        return in_array($legacy, ['expense', 'purchase', 'gasto'], true) ? 'expense' : 'sale';
    }

    private function normalizeStatus(?string $legacy): string
    {
        return in_array($legacy, ['draft', 'sent', 'paid'], true) ? $legacy : 'draft';
    }

    private function normalizePaymentStatus(?string $legacy): string
    {
        return in_array($legacy, ['unpaid', 'partial', 'paid', 'pending'], true) ? $legacy : 'unpaid';
    }
}
