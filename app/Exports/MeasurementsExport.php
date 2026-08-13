<?php

namespace App\Exports;

use App\Models\Measurement;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Exports the CURRENT FILTERED VIEW of the measurements screen — driven by the
 * same query the list shows, so "export what I'm looking at" is literal.
 *
 * @implements WithMapping<Measurement>
 */
class MeasurementsExport implements FromQuery, WithHeadings, WithMapping
{
    /**
     * @param  Builder<Measurement>  $query
     */
    public function __construct(private Builder $query) {}

    /**
     * @return Builder<Measurement>
     */
    public function query(): Builder
    {
        return $this->query->with(['project:id,name', 'employee:id,full_name']);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Fecha / Date',
            'Obra / Project',
            'Trabajador / Employee',
            'Cantidad / Quantity',
            'Unidad / Unit',
            'Tipo / Type',
            'Estado / Status',
            'Motivo de rechazo / Rejection reason',
            'Notas / Notes',
        ];
    }

    /**
     * @param  Measurement  $measurement
     * @return list<string|float|null>
     */
    public function map($measurement): array
    {
        return [
            $measurement->date->toDateString(),
            $measurement->project?->name,
            $measurement->employee?->full_name,
            (float) $measurement->quantity,
            $measurement->unit,
            $measurement->measurement_type->value,
            $measurement->status->value,
            $measurement->rejection_reason,
            $measurement->notes,
        ];
    }
}
