<?php

use App\Enums\NotificationType;

/**
 * The Settings notification matrix renders `$t("settings.ntype_{type}")` for
 * every non-worker-direct NotificationType (Admin/Settings.vue). A type added
 * without its label shows the raw key on screen (found live 2026-08-16 for
 * invoice_reminder / worker_off_site / inventory_low_stock / equipment_overdue /
 * ppe_expiring / ppe_missing). This guard fails the build if any matrix type is
 * missing its ES or EN label.
 */
it('has a settings.ntype_ label in both locales for every matrix notification type', function (): void {
    $root = dirname(__DIR__, 2);
    $es = require $root.'/lang/es/ui.php';
    $en = require $root.'/lang/en/ui.php';

    foreach (NotificationType::cases() as $type) {
        if ($type->isWorkerDirect()) {
            continue; // worker-direct types are not shown in the settings matrix
        }

        $key = 'ntype_'.$type->value;

        expect($es['settings'][$key] ?? null)->not->toBeNull("Missing ES settings.{$key}");
        expect($en['settings'][$key] ?? null)->not->toBeNull("Missing EN settings.{$key}");
    }
});
