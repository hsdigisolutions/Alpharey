<?php

/**
 * Bare withoutGlobalScopes() on a SOFT-DELETING model strips SoftDeletes
 * along with the tenant scope — and soft deletion does not flip `active`,
 * so a "deleted" employee quietly stays inside every cross-company engine.
 * This exact mistake shipped three times (CompanyRemovalGuard, the payroll
 * run, the SA company screen) before this guard existed.
 *
 * Cross-company code drops ONLY the tenant scope:
 *     Employee::query()->withoutGlobalScope(CompanyScope::class)
 *
 * Deliberate trashed access says so explicitly with withTrashed().
 */
it('never strips all global scopes off a soft-deleting model', function (): void {
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../app', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // Employee and Client are the soft-deleting models (dev-skill Rule 9).
        if (preg_match('/(?:Employee|Client)::query\(\)\s*->\s*withoutGlobalScopes\(\)/s', $source)) {
            $offenders[] = basename($file->getPathname());
        }
    }

    expect($offenders)->toBe(
        [],
        'Bare withoutGlobalScopes() on a soft-deleting model — use withoutGlobalScope(CompanyScope::class) so SoftDeletes stays in force',
    );
});
