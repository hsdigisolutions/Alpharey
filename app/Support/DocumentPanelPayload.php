<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Models\Vendor;
use App\Services\Documents\DocumentStatus;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Builds the smart-document-panel payload — the current version of each type
 * plus its metadata, contacts, per-type field defs and prior-version history —
 * for any documentable entity (company, employee, project). One builder so the
 * three surfaces can never drift.
 */
class DocumentPanelPayload
{
    public function __construct(private DocumentStatus $status) {}

    /**
     * Query an entity's documents and build the panel rows.
     *
     * @return list<array<string, mixed>>
     */
    public function forEntity(Company|Employee|Project|Client|Vendor $entity, string $entityType, ?string $ccc = null): array
    {
        /** @var EloquentCollection<int, Document> $all */
        $all = $entity->documents()->with('uploader:id,name')->orderByDesc('version')->get();

        return $this->rows($all, $entityType, $ccc);
    }

    /**
     * Build the panel rows from an already-loaded document collection (current
     * + history). Avoids re-querying when the caller eager-loaded them.
     *
     * @param  Collection<int, Document>  $allDocs
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $allDocs, string $entityType, ?string $ccc = null): array
    {
        $current = $allDocs->where('is_current', true)->values();
        $history = $allDocs->where('is_current', false)->groupBy('type_key');

        return $current
            ->map(fn (Document $document): array => $this->row(
                $document,
                $entityType,
                $ccc,
                $history->get($document->type_key, collect()),
            ))
            ->values()
            ->all();
    }

    /**
     * The panel payload for ONE document — the on-demand View in the Document
     * Command Center. History is filtered by the doc's own company so shared
     * client/vendor version chains stay per-company.
     *
     * @return array<string, mixed>
     */
    public function single(Document $document): array
    {
        $entityType = match ($document->documentable_type) {
            Company::class => 'company',
            Project::class => 'project',
            Client::class => 'client',
            Vendor::class => 'vendor',
            default => 'employee',
        };

        $history = Document::query()->withoutGlobalScope(CompanyScope::class)
            ->where('documentable_type', $document->documentable_type)
            ->where('documentable_id', $document->documentable_id)
            ->where('type_key', $document->type_key)
            ->where('company_id', $document->company_id)
            ->where('is_current', false)
            ->with('uploader:id,name')
            ->get();

        $ccc = $document->documentable_type === Company::class
            ? Company::query()->whereKey($document->documentable_id)->value('ccc')
            : null;

        return $this->row($document->loadMissing('uploader'), $entityType, is_string($ccc) ? $ccc : null, $history);
    }

    /**
     * @param  Collection<int, Document>  $history
     * @return array<string, mixed>
     */
    private function row(Document $document, string $entityType, ?string $ccc, Collection $history): array
    {
        [$state, $daysLeft] = $this->status->of($document);

        $fieldDefs = DocumentTypes::fieldsFor($entityType, $document->type_key);
        $hasCcc = collect($fieldDefs)->contains(fn (array $f): bool => $f['type'] === 'ccc');

        return [
            'id' => $document->id,
            'category' => $document->category,
            'type_key' => $document->type_key,
            'name' => $document->name,
            'original_name' => $document->original_name,
            'has_file' => $document->getAttribute('file_path') !== null,
            // Drives the inline-preview choice (image lightbox vs PDF viewer).
            'mime' => $document->mime,
            'has_flag' => $document->has_flag,
            'issue_date' => $document->issue_date?->toDateString(),
            'expiry_date' => $document->expiry_date?->toDateString(),
            'notes' => $document->notes,
            'version' => $document->version,
            'status' => $state,
            'days_left' => $daysLeft,
            'uploaded_at' => $document->created_at?->toDateString(),
            'uploaded_by' => $document->uploader?->name,
            'metadata' => $document->metadata ?? [],
            'contacts' => $document->contacts ?? [],
            'field_defs' => $fieldDefs,
            'ccc' => $hasCcc ? $ccc : null,
            'history' => $history
                ->sortByDesc('version')
                ->map(fn (Document $version): array => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'uploaded_at' => $version->created_at?->toDateString(),
                    'uploaded_by' => $version->uploader?->name,
                    'issue_date' => $version->issue_date?->toDateString(),
                    'expiry_date' => $version->expiry_date?->toDateString(),
                    'has_file' => $version->getAttribute('file_path') !== null,
                ])
                ->values()
                ->all(),
        ];
    }
}
