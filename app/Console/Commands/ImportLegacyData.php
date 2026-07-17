<?php

namespace App\Console\Commands;

use App\Services\LegacyImport\AbstractImporter;
use App\Services\LegacyImport\ImportResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLegacyData extends Command
{
    protected $signature = 'verto:import-legacy
        {--dry-run : Execute fully but roll back all writes; still produces exception reports}
        {--only=* : Run only the named importer(s), e.g. --only=employees}';

    protected $description = 'Import data from the legacy VertoCRM database (DATA_MIGRATION.md)';

    public function handle(): int
    {
        if ((string) config('database.connections.legacy.database') === '') {
            $this->components->error(
                'The legacy database connection is not configured. Restore the dump from the '
                .'live server locally, then set LEGACY_DB_DATABASE / LEGACY_DB_USERNAME / '
                .'LEGACY_DB_PASSWORD in .env (see DATA_MIGRATION.md §1).'
            );

            return self::FAILURE;
        }

        /** @var list<class-string<AbstractImporter>> $classes */
        $classes = config('legacy-import.importers', []);

        $only = array_map(strval(...), (array) $this->option('only'));

        $importers = collect($classes)
            ->map(fn (string $class): AbstractImporter => app($class))
            ->when($only !== [], fn ($importers) => $importers->filter(
                fn (AbstractImporter $importer): bool => in_array($importer->name(), $only, true),
            ));

        if ($importers->isEmpty()) {
            $this->components->warn(
                'No importers registered (or none match --only). Importers are added to '
                .'config/legacy-import.php phase by phase — see DATA_MIGRATION.md §5.'
            );

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->components->info('DRY RUN — all writes will be rolled back.');
            // One transaction around the WHOLE sequence: importers commit into
            // it (their savepoints release, so a later importer can resolve the
            // ids an earlier one recorded), and this outer transaction rolls
            // the lot back at the end. Without this, dry-run would roll back
            // each importer before the next ran and every dependency would
            // look broken — see AbstractImporter::run().
            DB::beginTransaction();
        }

        try {
            $results = $importers->map(function (AbstractImporter $importer) use ($dryRun): ImportResult {
                $this->components->task(
                    $importer->name(),
                    function () use ($importer, $dryRun, &$result): bool {
                        // In dry-run WE hold the outer transaction, so the
                        // importer must not roll back its own work.
                        $result = $importer->run($dryRun, ownsTransaction: ! $dryRun);

                        return true;
                    },
                );

                return $result;
            })->all();
        } finally {
            if ($dryRun && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $results = collect($results);

        $this->table(
            ['Importer', 'Imported', 'Skipped', 'Exceptions', 'Report'],
            $results->map(fn (ImportResult $result): array => [
                $result->importer,
                $result->imported,
                $result->skipped,
                $result->exceptions,
                $result->exceptionsReportPath ?? '—',
            ]),
        );

        return self::SUCCESS;
    }
}
