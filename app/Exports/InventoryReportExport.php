<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * A generic inventory report sheet — the InventoryController builds the headings
 * + rows for the chosen report (items / movements / issues / PPE compliance) and
 * this just renders them. Keeps one export class for all four reports.
 */
class InventoryReportExport implements FromArray, WithHeadings
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|float|int|null>>  $rows
     */
    public function __construct(private array $headings, private array $rows) {}

    /**
     * @return list<list<string|float|int|null>>
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
