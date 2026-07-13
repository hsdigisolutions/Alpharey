<?php

/**
 * Registry for verto:import-legacy. Importers run in the order listed —
 * dependency order matters (users before employees, projects before
 * attendance, …). Each build phase registers its importers here as the
 * matching module lands (DATA_MIGRATION.md §5).
 */
return [

    'importers' => [
        // Phase 1: users, companies, settings
        // Phase 2: employees, wage data, documents, notes, call logs
        // Phase 3: clients, vendors, projects, proposals
        // Phase 4: attendance, measurements, production tasks
        // Phase 6: expenses, invoices, payments, payrolls, advances, commissions
        // Phase 7: leaves, vehicles, inventory
        // Phase 8: audit archive, notifications
    ],

];
