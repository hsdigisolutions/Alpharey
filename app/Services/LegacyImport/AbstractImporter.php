<?php

namespace App\Services\LegacyImport;

use App\Models\LegacyIdMap;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Base class for legacy VertoCRM importers (DATA_MIGRATION.md §2).
 *
 * Guarantees provided here so concrete importers stay simple:
 *  - idempotency via the legacy_id_map table (re-runs skip mapped rows)
 *  - dry-run mode: the entire run executes inside a transaction that is
 *    rolled back, and the exceptions report is still produced
 *  - an exceptions CSV per run — ambiguous rows are reported, never guessed
 *
 * Concrete importers MUST read via $this->legacy(...) (read-only connection)
 * and write through the new system's Eloquent models so encrypted casts and
 * blind indexes apply. Company-scoped models must be written with an explicit
 * company_id and queried via ::acrossAllCompanies() — importers run without
 * an authenticated user, where the tenancy scope returns zero rows by design.
 */
abstract class AbstractImporter
{
    protected bool $dryRun = false;

    protected int $imported = 0;

    protected int $skipped = 0;

    /** @var list<array{legacy_table: string, legacy_id: string, reason: string, context: string}> */
    private array $exceptions = [];

    /**
     * Short unique name, e.g. "employees". Used for id mapping and reports.
     */
    abstract public function name(): string;

    /**
     * Perform the import. Increment $this->imported / $this->skipped and
     * call $this->exception() for anything a human must resolve.
     */
    abstract protected function import(): void;

    public function run(bool $dryRun = false): ImportResult
    {
        $this->dryRun = $dryRun;
        $this->imported = 0;
        $this->skipped = 0;
        $this->exceptions = [];

        DB::beginTransaction();

        try {
            $this->import();

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return new ImportResult(
            importer: $this->name(),
            dryRun: $dryRun,
            imported: $this->imported,
            skipped: $this->skipped,
            exceptions: count($this->exceptions),
            exceptionsReportPath: $this->writeExceptionsReport(),
        );
    }

    /**
     * Query builder on the legacy database (read-only by convention —
     * never write to this connection).
     */
    protected function legacy(string $table): Builder
    {
        return DB::connection('legacy')->table($table);
    }

    protected function recordMapping(string|int $legacyId, string|int $newId, ?string $entityType = null): void
    {
        LegacyIdMap::query()->create([
            'entity_type' => $entityType ?? $this->name(),
            'legacy_id' => (string) $legacyId,
            'new_id' => (string) $newId,
        ]);
    }

    protected function newIdFor(string|int $legacyId, ?string $entityType = null): ?string
    {
        return LegacyIdMap::query()
            ->where('entity_type', $entityType ?? $this->name())
            ->where('legacy_id', (string) $legacyId)
            ->value('new_id');
    }

    protected function alreadyImported(string|int $legacyId, ?string $entityType = null): bool
    {
        return $this->newIdFor($legacyId, $entityType) !== null;
    }

    /**
     * Record a row that needs human judgment. Nothing is ever silently guessed.
     *
     * @param  array<string, mixed>  $context
     */
    protected function exception(string $legacyTable, string|int|null $legacyId, string $reason, array $context = []): void
    {
        $this->exceptions[] = [
            'legacy_table' => $legacyTable,
            'legacy_id' => (string) ($legacyId ?? ''),
            'reason' => $reason,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
        ];
    }

    private function writeExceptionsReport(): ?string
    {
        if ($this->exceptions === []) {
            return null;
        }

        $directory = storage_path('app/import-exceptions');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = sprintf('%s/%s-%s%s.csv', $directory, $this->name(), now()->format('Ymd-His'), $this->dryRun ? '-dry-run' : '');

        $handle = fopen($path, 'w');
        fputcsv($handle, ['legacy_table', 'legacy_id', 'reason', 'context']);

        foreach ($this->exceptions as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }
}
