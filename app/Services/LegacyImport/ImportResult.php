<?php

namespace App\Services\LegacyImport;

final readonly class ImportResult
{
    public function __construct(
        public string $importer,
        public bool $dryRun,
        public int $imported,
        public int $skipped,
        public int $exceptions,
        public ?string $exceptionsReportPath,
    ) {}
}
