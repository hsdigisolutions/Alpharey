<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One titled worksheet built from pre-made headings + rows — the building block
 * for multi-sheet workbooks (e.g. a Summary tab + a Detail tab).
 */
class ArraySheet implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  list<array<int, string|int|float|null>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    public function title(): string
    {
        return $this->title;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return list<array<int, string|int|float|null>>
     */
    public function array(): array
    {
        return $this->rows;
    }
}
