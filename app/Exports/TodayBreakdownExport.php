<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Today's Report — the "Project Breakdown" section as a spreadsheet:
 * Project · Employees (name + day-count) · Assigned · Present · Absent · Hours,
 * with a final TOTALS row. Built for a clean, readable share (Change 2/3).
 */
class TodayBreakdownExport implements FromArray, WithHeadings
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int|float>  $totals
     */
    public function __construct(private readonly array $rows, private readonly array $totals) {}

    /**
     * @return list<array<int, string|int|float>>
     */
    public function array(): array
    {
        $data = array_map(fn (array $r): array => [
            (string) ($r['project'] ?? 'Sin proyecto / No project'),
            self::employeeList($r['employees'] ?? []),
            (int) ($r['assigned'] ?? 0),
            (int) ($r['present'] ?? 0),
            (int) ($r['absent'] ?? 0),
            (float) ($r['hours'] ?? 0),
        ], $this->rows);

        // Totals row — employees shown both ways so the two figures never blur.
        $data[] = [
            'TOTAL ('.((int) ($this->totals['projects'] ?? 0)).' proyectos / projects)',
            ((int) ($this->totals['unique_employees'] ?? 0)).' únicos/unique · '.((int) ($this->totals['employee_instances'] ?? 0)).' asignaciones/instances',
            (int) ($this->totals['assigned'] ?? 0),
            (int) ($this->totals['present'] ?? 0),
            (int) ($this->totals['absent'] ?? 0),
            (float) ($this->totals['hours'] ?? 0),
        ];

        return $data;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Project', 'Employees (days)', 'Assigned', 'Present', 'Absent', 'Hours'];
    }

    /**
     * "Name (days), Name (days)" — the same format shown on screen.
     *
     * @param  list<array<string, mixed>>  $employees
     */
    private static function employeeList(array $employees): string
    {
        return implode(', ', array_map(
            fn (array $e): string => (string) ($e['name'] ?? '?').' ('.((int) ($e['days'] ?? 0)).')',
            $employees,
        ));
    }
}
