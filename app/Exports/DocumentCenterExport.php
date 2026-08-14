<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The Document Command Center's current filtered view as a flat sheet — one row
 * per document / missing slot / vehicle expiry. Used for both Excel and (via
 * displayRows) the PDF blade.
 */
class DocumentCenterExport implements FromArray, WithHeadings
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(private array $rows) {}

    /**
     * @return list<array<int, string>>
     */
    public function array(): array
    {
        return $this->displayRows();
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Estado / Status', 'Tipo / Type', 'Pertenece a / Belongs to', 'Categoría / Category', 'Empresa / Company', 'Vencimiento / Expiry', 'Subido / Uploaded'];
    }

    /**
     * @return list<array<int, string>>
     */
    public function displayRows(): array
    {
        return array_map(fn (array $row): array => [
            (string) $row['status'],
            __('ui.doc_types.'.$row['type_key']) !== 'ui.doc_types.'.$row['type_key']
                ? (string) __('ui.doc_types.'.$row['type_key'])
                : (string) $row['type_key'],
            (string) ($row['entity_name'] ?? '—'),
            (string) $row['entity_type'],
            (string) ($row['company_name'] ?? '—'),
            (string) ($row['expiry_date'] ?? '—'),
            (string) ($row['uploaded_at'] ?? '—'),
        ], $this->rows);
    }
}
