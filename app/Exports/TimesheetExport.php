<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Timesheet — one employee's day-by-day rows for the period as a spreadsheet.
 */
class TimesheetExport implements FromArray, WithHeadings
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows, private readonly string $employee) {}

    /**
     * @return list<array<int, string|float|int|null>>
     */
    public function array(): array
    {
        return array_map(fn (array $r): array => [
            (string) ($r['date'] ?? ''),
            (string) ($r['project'] ?? '—'),
            (string) ($r['check_in'] ?? '—'),
            (string) ($r['check_out'] ?? '—'),
            $r['hours'] !== null ? (float) $r['hours'] : '—',
            (string) ($r['day_type'] ?? '—'),
            (string) ($r['status'] ?? ''),
        ], $this->rows);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Date ('.$this->employee.')', 'Project', 'Check-in', 'Check-out', 'Hours', 'Day Type', 'Status'];
    }
}
