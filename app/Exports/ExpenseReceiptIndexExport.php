<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The reconciliation index that ships inside the receipt ZIP: one row per
 * receipt, matching each factura file to its expense record (date · vendor ·
 * category · base · IVA · total · payment method · filename) — the receipt↔record
 * mapping a Spanish gestor/asesor works from for the Libro de facturas recibidas.
 */
class ExpenseReceiptIndexExport implements FromArray, WithHeadings
{
    /**
     * @param  list<array<string, mixed>>  $rows  Rows from ExpenseReceiptExport::row()
     */
    public function __construct(private array $rows) {}

    /**
     * @return list<list<string|float|null>>
     */
    public function array(): array
    {
        return array_map(fn (array $r): array => [
            $r['date'],
            $r['number'],
            $r['vendor'],
            $r['project'],
            $r['category'],
            $r['concept'],
            $r['base'],
            $r['vat'],
            $r['total'],
            ($r['is_taxable'] ?? true) ? 'Sujeta / Taxable' : 'No sujeta / Non-taxable',
            $r['payment_method'],
            $r['filename'],
        ], $this->rows);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Fecha / Date',
            'Nº factura / Invoice no.',
            'Proveedor / Vendor',
            'Obra / Project',
            'Categoría / Category',
            'Concepto / Concept',
            'Base / Taxable base',
            'IVA / VAT',
            'Total',
            'Sujeción IVA / VAT status',
            'Forma de pago / Payment method',
            'Archivo / File',
        ];
    }
}
