<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use Illuminate\Support\Facades\DB;

/**
 * Invoice create/update pipeline. `number` is server-generated and totals come
 * from InvoiceTotals — neither is ever accepted from input.
 *
 * The issuing company is passed in rather than resolved here: an invoice always
 * belongs to exactly one company, and the caller is the one that knows whether
 * a company context exists (a Super Admin browsing "all companies" has none).
 */
class InvoiceService
{
    public function __construct(private readonly InvoiceTotals $totals) {}

    /**
     * @param  array<string, mixed>  $data  validated payload; 'lines' is the line-item array
     */
    public function create(array $data, int $companyId): Invoice
    {
        return DB::transaction(function () use ($data, $companyId): Invoice {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $invoice = new Invoice($data);
            $invoice->company_id = $companyId;
            $invoice->number = Invoice::nextNumber($companyId);
            $invoice->save();

            $this->syncLines($invoice, $lines);
            $this->totals->apply($invoice);

            return $invoice;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data): Invoice {
            $lines = $data['lines'] ?? null;
            unset($data['lines'], $data['number']); // number is never re-assigned

            $invoice->fill($data);
            $invoice->save();

            if ($lines !== null) {
                $this->syncLines($invoice, $lines);
            }

            $this->totals->apply($invoice);

            return $invoice;
        });
    }

    /**
     * Replace the line items wholesale — simpler and safer than diffing, and
     * the totals are recomputed from them anyway.
     *
     * @param  list<array{description: string, quantity: float|string, unit_price: float|string}>  $lines
     */
    private function syncLines(Invoice $invoice, array $lines): void
    {
        $invoice->lineItems()->delete();

        foreach ($lines as $i => $line) {
            $item = new InvoiceLineItem([
                'description' => $line['description'],
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'line_total' => (string) $this->totals->lineTotal($line),
                'sort_order' => $i,
            ]);
            $item->invoice_id = $invoice->id;
            $item->save();
        }
    }
}
