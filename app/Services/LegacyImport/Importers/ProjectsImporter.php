<?php

namespace App\Services\LegacyImport\Importers;

use App\Models\Company;
use App\Models\Project;
use App\Services\LegacyImport\AbstractImporter;

/**
 * Legacy projects → new projects (DATA_MIGRATION.md §3.11). Projects are
 * company-owned; the legacy system was single-company so all attach to
 * Company 1. client_id is remapped through the clients importer's id map.
 */
class ProjectsImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'projects';
    }

    protected function import(): void
    {
        $defaultCompanyId = Company::query()->orderBy('id')->value('id');

        if ($defaultCompanyId === null) {
            $this->exception('projects', null, 'No companies exist — run the seeder first.');

            return;
        }

        $this->legacy('projects')->orderBy('id')->chunk(200, function ($rows) use ($defaultCompanyId): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                // Remap the legacy client id → new client id
                $newClientId = $row->client_id !== null
                    ? $this->newIdFor($row->client_id, 'clients')
                    : null;

                if ($row->client_id !== null && $newClientId === null) {
                    $this->exception('projects', $row->id, 'Client not yet imported — run the clients importer first', [
                        'legacy_client_id' => $row->client_id,
                    ]);
                }

                $project = new Project([
                    'client_id' => $newClientId,
                    'name' => $row->name ?? 'Sin nombre',
                    'project_type' => $row->project_type ?? null,
                    'status' => $this->normalizeStatus($row->status ?? null),
                    'priority' => $this->normalizePriority($row->priority ?? null),
                    'start_date' => $row->start_date ?? null,
                    'end_date' => $row->end_date ?? null,
                    'budget' => $row->budget ?? null,
                    'description' => $row->description ?? null,
                ]);
                $project->company_id = $defaultCompanyId;
                $project->code = ($row->code ?? '') !== '' ? $row->code : Project::nextCode((int) $defaultCompanyId);
                $project->save();

                $this->recordMapping($row->id, $project->id);
                $this->imported++;
            }
        });
    }

    private function normalizeStatus(?string $legacy): string
    {
        return in_array($legacy, ['active', 'in_progress', 'completed', 'cancelled', 'on_hold'], true) ? $legacy : 'active';
    }

    private function normalizePriority(?string $legacy): string
    {
        return in_array($legacy, ['low', 'medium', 'high', 'urgent'], true) ? $legacy : 'medium';
    }
}
