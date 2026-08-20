<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Today's Report — the filtered worker-detail rows as a spreadsheet.
 */
class TodayExport implements FromArray, WithHeadings
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows) {}

    /**
     * @return list<array<int, string|float|int|null>>
     */
    public function array(): array
    {
        return array_map(fn (array $r): array => [
            (string) ($r['date'] ?? ''),
            (string) ($r['employee'] ?? ''),
            (string) ($r['project'] ?? '—'),
            (string) ($r['check_in'] ?? '—'),
            (string) ($r['check_out'] ?? '—'),
            (float) ($r['hours'] ?? 0),
            (string) ($r['status'] ?? ''),
            $r['distance'] !== null ? round((float) $r['distance']).' m' : '—',
        ], $this->rows);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Date', 'Worker', 'Project', 'Check-in', 'Check-out', 'Hours', 'Status', 'Distance'];
    }
}
