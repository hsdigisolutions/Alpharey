<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * A tiny reusable Excel export: given headings + pre-built rows, write them.
 */
class GenericRowsExport implements FromArray, WithHeadings
{
    /**
     * @param  list<string>  $headings
     * @param  list<array<int, string|int|float|null>>  $rows
     */
    public function __construct(private readonly array $headings, private readonly array $rows) {}

    /**
     * @return list<array<int, string|int|float|null>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }
}
