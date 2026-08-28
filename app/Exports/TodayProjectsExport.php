<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Today's Report — the "Projects with no activity today" section as a
 * spreadsheet: Project · Assigned Workers · Last Activity.
 */
class TodayProjectsExport implements FromArray, WithHeadings
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows) {}

    /**
     * @return list<array<int, string|int>>
     */
    public function array(): array
    {
        return array_map(fn (array $r): array => [
            (string) ($r['project'] ?? ''),
            (int) ($r['assigned'] ?? 0),
            $r['last_activity'] !== null ? (string) $r['last_activity'] : 'Never',
        ], $this->rows);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Project', 'Assigned Workers', 'Last Activity'];
    }
}
