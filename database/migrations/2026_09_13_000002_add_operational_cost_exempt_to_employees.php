<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 7 (2026-09-13) — operational cost % overhead (cost-tracking only).
 *
 * A company can set an "operational cost %" (a per-company Setting); the P&L then
 * shows an ADDITIVE "operational overhead" line = Σ(worker frozen day total × %),
 * over the workers it applies to. Workers are included by default; this flag
 * exempts specific ones (office staff, special arrangements). Reporting/display
 * only — it never touches payroll or the existing profit figure. Additive +
 * defaulted false, so every existing worker is included and unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->boolean('operational_cost_exempt')->default(false)->after('works_at_height');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('operational_cost_exempt');
        });
    }
};
