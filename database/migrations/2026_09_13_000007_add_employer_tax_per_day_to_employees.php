<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee employer social-security tax (2026-09-13).
 *
 * The FIXED euro amount the COMPANY pays to the government per DAY for employing
 * this worker (social security / cotización) — different for each worker, entered
 * by the admin (NOT a percentage, NOT derived). Added to the worker's wage to
 * form the TRUE labour cost in the P&L (a genuine cost, before profit); it never
 * touches the worker's pay or the client's bill.
 *
 * A plain decimal (NOT encrypted) — the P&L sums it in SQL over worked attendance
 * rows, so it cannot be an encrypted column. Default 0 → every unconfigured worker
 * adds nothing and the P&L equals wages-only until real amounts are entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->decimal('employer_tax_per_day', 14, 2)->default(0)->after('per_meter_rate');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('employer_tax_per_day');
        });
    }
};
