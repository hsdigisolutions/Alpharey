<?php

namespace App\Services\LegacyImport\Importers;

use App\Models\Client;
use App\Services\LegacyImport\AbstractImporter;

/**
 * Legacy clients → new clients (DATA_MIGRATION.md §3.11). Clients are a
 * shared pool (no company), so this is a straight 1:1 map. Numeric ids are
 * preserved. Duplicate NIFs are reported, not merged.
 */
class ClientsImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'clients';
    }

    protected function import(): void
    {
        $this->legacy('clients')->orderBy('id')->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                $client = Client::query()->create([
                    'name' => $row->name ?? 'Sin nombre',
                    'company_name' => $row->company_name ?? null,
                    'nif' => $row->nif ?? null,
                    'vat_number' => $row->vat_number ?? null,
                    'client_type' => $this->normalizeType($row->client_type ?? null),
                    'contact_person' => $row->contact_person ?? null,
                    'phone' => $row->phone ?? null,
                    'mobile' => $row->mobile ?? null,
                    'email' => $row->email ?? null,
                    'address' => $row->address ?? null,
                    'city' => $row->city ?? null,
                    'postal_code' => $row->postal_code ?? null,
                    'country' => $row->country ?? null,
                    'website' => $row->website ?? null,
                    'bank_account' => $row->bank_account ?? null,
                    'payment_terms' => $row->payment_terms ?? 'net30',
                    'active' => (bool) ($row->active ?? true),
                ]);

                $this->recordMapping($row->id, $client->id);
                $this->imported++;
            }
        });
    }

    private function normalizeType(?string $legacy): string
    {
        return in_array($legacy, ['company', 'private', 'municipality', 'other'], true) ? $legacy : 'company';
    }
}
