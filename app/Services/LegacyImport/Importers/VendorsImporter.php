<?php

namespace App\Services\LegacyImport\Importers;

use App\Models\Vendor;
use App\Services\LegacyImport\AbstractImporter;

/**
 * Legacy vendors → new vendors (DATA_MIGRATION.md §3.11). Shared pool, 1:1.
 */
class VendorsImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'vendors';
    }

    protected function import(): void
    {
        $this->legacy('vendors')->orderBy('id')->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                $vendor = Vendor::query()->create([
                    'name' => $row->name ?? 'Sin nombre',
                    'company_name' => $row->company_name ?? null,
                    'nif' => $row->nif ?? null,
                    'phone' => $row->phone ?? null,
                    'email' => $row->email ?? null,
                    'address' => $row->address ?? null,
                    'city' => $row->city ?? null,
                    'payment_terms' => $row->payment_terms ?? null,
                    'active' => (bool) ($row->active ?? true),
                ]);

                $this->recordMapping($row->id, $vendor->id);
                $this->imported++;
            }
        });
    }
}
