<?php

use App\Services\LegacyImport\Importers\AdvancesImporter;
use App\Services\LegacyImport\Importers\AttendanceImporter;
use App\Services\LegacyImport\Importers\ClientsImporter;
use App\Services\LegacyImport\Importers\EmployeesImporter;
use App\Services\LegacyImport\Importers\ExpensesImporter;
use App\Services\LegacyImport\Importers\InventoryImporter;
use App\Services\LegacyImport\Importers\InvoicesImporter;
use App\Services\LegacyImport\Importers\LeavesImporter;
use App\Services\LegacyImport\Importers\PayrollsImporter;
use App\Services\LegacyImport\Importers\ProjectsImporter;
use App\Services\LegacyImport\Importers\SettingsImporter;
use App\Services\LegacyImport\Importers\UsersImporter;
use App\Services\LegacyImport\Importers\VehiclesImporter;
use App\Services\LegacyImport\Importers\VendorsImporter;

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
        // Phase 3: clients/vendors before projects (projects remap client ids)
        ClientsImporter::class,
        VendorsImporter::class,
        ProjectsImporter::class,
        // Phase 4: attendance (remaps employee + project ids; run after them)
        AttendanceImporter::class,
        // Phase 5: cross-company deployments have NO importer — the legacy system
        // was single-company (all data maps to Company 1, DATA_MIGRATION.md §3.1),
        // so it never modelled a deployment BETWEEN companies. Deployments are a
        // net-new feature; there is nothing to migrate.

        // Phase 6 — money. Order matters: invoices remap client/vendor/project
        // ids, expenses remap vendor/project/employee, payrolls + advances remap
        // employees. All financial figures migrate VERBATIM (DATA_MIGRATION.md
        // §3.5) — never recomputed by the new engines.
        InvoicesImporter::class,
        ExpensesImporter::class,
        PayrollsImporter::class,
        AdvancesImporter::class,
        // Commission entries are derived from invoices by CommissionService and
        // the legacy "settlement engine" is dead code (DATA_MIGRATION.md §5) —
        // no importer; the client regenerates a month if they want it.
        // Phase 7 — operations. Order matters: vehicles and inventory remap
        // employee/project ids, and leaves must run after employees because it
        // reconstructs the user -> employee link the legacy schema never had
        // (by name; ambiguous rows are reported, never guessed —
        // DATA_MIGRATION.md §3.7).
        VehiclesImporter::class,
        InventoryImporter::class,
        LeavesImporter::class,
        // Phase 8: audit archive, notifications
    ],

];
