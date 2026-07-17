<?php

namespace App\Exports;

use App\Models\CommissionReportEntry;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Commission month export (Screen 19).
 *
 * Carries BOTH the original and the adjusted amount plus the reason — the
 * export is what gets mailed around, so it must show what changed and why,
 * not just the final figure.
 *
 * @implements WithMapping<CommissionReportEntry>
 */
class CommissionsExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, CommissionReportEntry>  $entries
     */
    public function __construct(private Collection $entries) {}

    /**
     * @return Collection<int, CommissionReportEntry>
     */
    public function collection(): Collection
    {
        return $this->entries;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Mes / Month',
            'Empleado / Employee',
            'Obra / Project',
            'Factura / Invoice',
            '% Comisión / Commission %',
            'Base',
            'Total factura / Invoice total',
            'Cobrado / Amount paid',
            'Comisión original / Original commission',
            'Comisión ajustada / Adjusted commission',
            'Motivo del ajuste / Adjustment reason',
            'A pagar / Payable',
            'Estado / Status',
            'Cerrada / Finalized at',
            'Pagada / Paid at',
        ];
    }

    /**
     * @param  CommissionReportEntry  $entry
     * @return list<string|float|null>
     */
    public function map($entry): array
    {
        return [
            $entry->month,
            $entry->employee?->full_name,
            $entry->project?->name,
            $entry->invoice?->number,
            (float) $entry->commission_percent,
            (float) $entry->base_amount,
            $entry->invoice !== null ? (float) $entry->invoice->total : null,
            $entry->invoice !== null ? (float) $entry->invoice->paid_amount : null,
            (float) $entry->original_amount,
            $entry->adjusted_amount !== null ? (float) $entry->adjusted_amount : null,
            $entry->adjustment_reason,
            $entry->payableAmount(),
            $entry->status->value,
            $entry->finalized_at?->toDateTimeString(),
            $entry->paid_at?->toDateString(),
        ];
    }
}
