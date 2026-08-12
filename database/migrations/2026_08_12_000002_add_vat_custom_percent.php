<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom VAT rate (client request 2026-08-12): when vat_rate = 'custom', the
     * actual percentage is this per-record value. Overrides the "Spain rates
     * only" default for the cases where a non-official rate is genuinely needed;
     * null for every other rate.
     */
    public function up(): void
    {
        foreach (['invoices', 'expenses', 'proposals'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->decimal('vat_custom_percent', 5, 2)->nullable()->after('vat_rate');
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'expenses', 'proposals'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('vat_custom_percent');
            });
        }
    }
};
