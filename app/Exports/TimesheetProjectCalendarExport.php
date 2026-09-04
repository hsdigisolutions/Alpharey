<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Project timesheet CALENDAR workbook — a single "Calendario" sheet holding the
 * on-screen grid: a day-column axis, one row per worker with the day mark
 * (F / H / hours) per day, and the workers/día + horas/día footer rows. Built
 * from the same source as the table export, so the two reconcile.
 */
class TimesheetProjectCalendarExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  list<array<int, string|int|float>>  $rows
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    public function title(): string
    {
        return 'Calendario';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return list<array<int, string|int|float>>
     */
    public function array(): array
    {
        return $this->rows;
    }
}
