<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Project timesheet workbook — two tabs:
 *  - "Resumen": one row per worker (days present + hours).
 *  - "Detalle": one row per worker PER DAY (date · weekday · day type · hours),
 *    so each attendance day is an auditable line item for billing/payroll.
 * Both are built from the same source so the detail reconciles to the summary.
 */
class TimesheetProjectDetailExport implements WithMultipleSheets
{
    /**
     * @param  list<array<int, string|int|float|null>>  $summaryRows
     * @param  list<array<int, string|int|float|null>>  $detailRows
     */
    public function __construct(
        private readonly array $summaryRows,
        private readonly array $detailRows,
    ) {}

    /**
     * @return array<int, ArraySheet>
     */
    public function sheets(): array
    {
        return [
            new ArraySheet('Resumen', ['Trabajador', 'Designación', 'Días', 'Horas'], $this->summaryRows),
            new ArraySheet('Detalle', ['Trabajador', 'Fecha', 'Día', 'Tipo de jornada', 'Horas'], $this->detailRows),
        ];
    }
}
