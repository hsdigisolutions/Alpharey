<?php

namespace App\Exports;

use App\Models\Attendance;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Attendance export for the selected month (Screen 11). Wage totals are
 * included only for users who may see them.
 *
 * @implements WithMapping<Attendance>
 */
class AttendanceExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, Attendance>  $records
     */
    public function __construct(
        private Collection $records,
        private bool $withWages,
    ) {}

    /**
     * @return Collection<int, Attendance>
     */
    public function collection(): Collection
    {
        return $this->records;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $h = [
            'Fecha / Date', 'Empleado / Employee', 'Proyecto / Project',
            'Entrada / In', 'Salida / Out', 'Horas / Hours', 'Extra / OT',
            'Estado / Status',
        ];

        if ($this->withWages) {
            $h[] = 'Total €';
        }

        return $h;
    }

    /**
     * @param  Attendance  $record
     * @return list<mixed>
     */
    public function map($record): array
    {
        $row = [
            $record->date->toDateString(),
            $record->employee?->full_name,
            $record->project?->name,
            $record->check_in,
            $record->check_out,
            (float) $record->hours_worked,
            (float) $record->overtime_hours,
            $record->status->value,
        ];

        if ($this->withWages) {
            $row[] = (float) $record->total_amount;
        }

        return $row;
    }
}
