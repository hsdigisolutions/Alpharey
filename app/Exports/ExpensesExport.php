<?php

namespace App\Exports;

use App\Models\Expense;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Exports the CURRENT FILTERED VIEW of the expenses screen — the same query the
 * list is showing, so "export what I'm looking at" is literal.
 *
 * @implements WithMapping<Expense>
 */
class ExpensesExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, Expense>  $expenses
     */
    public function __construct(private Collection $expenses) {}

    /**
     * @return Collection<int, Expense>
     */
    public function collection(): Collection
    {
        return $this->expenses;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Número / Number',
            'Tipo / Type',
            'Categoría / Category',
            'Proveedor / Vendor',
            'Obra / Project',
            'Trabajador / Employee',
            'A cargo de / Bearable by',
            'Fecha / Date',
            'Vencimiento / Due date',
            'Base imponible / Subtotal',
            'IVA % / VAT %',
            'IVA / VAT',
            'Total',
            'Forma de pago / Payment method',
            'Estado de pago / Payment status',
            'Aprobado / Approved',
        ];
    }

    /**
     * @param  Expense  $expense
     * @return list<string|float|null>
     */
    public function map($expense): array
    {
        return [
            $expense->number,
            $expense->type->value,
            $expense->category?->name,
            $expense->vendor?->name,
            $expense->project?->name,
            $expense->employee?->full_name,
            $expense->bearable_by->value,
            $expense->date->toDateString(),
            $expense->due_date?->toDateString(),
            (float) $expense->subtotal,
            // Blank VAT stays blank — never 0% (DECISIONS.md)
            $expense->vat_rate?->percent(),
            (float) $expense->vat_amount,
            (float) $expense->total,
            $expense->payment_method?->value,
            $expense->payment_status->value,
            $expense->approved ? 'Sí / Yes' : 'No',
        ];
    }
}
