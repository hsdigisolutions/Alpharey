<?php

namespace App\Exports;

use App\Models\Invoice;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Exports the CURRENT FILTERED VIEW of one invoices tab (§10) — the same query
 * the screen is showing, so "export what I'm looking at" is literal.
 *
 * @implements WithMapping<Invoice>
 */
class InvoicesExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    public function __construct(private Collection $invoices) {}

    /**
     * @return Collection<int, Invoice>
     */
    public function collection(): Collection
    {
        return $this->invoices;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Número / Number',
            'Tipo / Type',
            'Subtipo / Sub-type',
            'Cliente / Client',
            'Proveedor / Vendor',
            'Obra / Project',
            'Empresa / Company',
            'Fecha / Invoice date',
            'Vencimiento / Due date',
            'Base imponible / Subtotal',
            'Descuento / Discount',
            'IVA % / VAT %',
            'IVA / VAT',
            'Retención % / Retention %',
            'Retención / Retention',
            'Total',
            'Pagado / Paid',
            'Estado / Status',
            'Estado de pago / Payment status',
            'Forma de pago / Payment method',
        ];
    }

    /**
     * @param  Invoice  $invoice
     * @return list<string|float|null>
     */
    public function map($invoice): array
    {
        return [
            $invoice->number,
            $invoice->type->value,
            $invoice->sub_type->value,
            $invoice->client?->name,
            $invoice->vendor?->name,
            $invoice->project?->name,
            $invoice->company?->name,
            $invoice->invoice_date->toDateString(),
            $invoice->due_date?->toDateString(),
            (float) $invoice->subtotal,
            (float) $invoice->discount_amount,
            // Blank VAT stays blank — never 0% (DECISIONS.md); a custom rate
            // exports its typed percentage, not 0.
            $invoice->vat_rate?->effectivePercent($invoice->vat_custom_percent),
            (float) $invoice->vat_amount,
            $invoice->retention_percent !== null ? (float) $invoice->retention_percent : null,
            (float) $invoice->retention_amount,
            (float) $invoice->total,
            (float) $invoice->paid_amount,
            $invoice->status->value,
            $invoice->payment_status->value,
            $invoice->payment_method?->value,
        ];
    }
}
