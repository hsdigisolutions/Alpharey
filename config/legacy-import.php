<?php

use App\Services\LegacyImport\Importers\EmployeesImporter;
use App\Services\LegacyImport\Importers\SettingsImporter;
use App\Services\LegacyImport\Importers\UsersImporter;

/**
 * Registry for verto:import-legacy. Importers run in the order listed —
 * dependency order matters (users before employees, projects before
 * attendance, …). Each build phase registers its importers here as the
 * matching module lands (DATA_MIGRATION.md §5).
 */
return [

    'importers' => [
        // Phase 1 (companies need no importer: the legacy system was
        // single-company; all data maps to Company 1 — DATA_MIGRATION.md §3.1)
        UsersImporter::class,
        SettingsImporter::class,
        // Phase 2: employees (documents/notes/calls follow once the live dump
        // arrives — the schema mapping is in DATA_MIGRATION.md §3.3/§3.6)
        EmployeesImporter::class,
        // Phase 3: clients, vendors, projects, proposals
        // Phase 4: attendance, measurements, production tasks
        // Phase 6: expenses, invoices, payments, payrolls, advances, commissions
        // Phase 7: leaves, vehicles, inventory
        // Phase 8: audit archive, notifications
    ],

];
