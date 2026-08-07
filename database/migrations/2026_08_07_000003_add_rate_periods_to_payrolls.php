<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-rate payroll breakdown. When a worker's rate changes mid-month, the
 * payslip groups their attendance into rate periods:
 *
 *   Período 1: 01 Jul → 10 Jul  (50,00 €/día)  8 días   400,00 €
 *   Período 2: 11 Jul → 31 Jul  (70,00 €/día)  15 días  1.050,00 €
 *
 * Stored as JSON, encrypted at rest like every other pay figure on the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->text('rate_periods')->nullable()->after('hours_amount'); // encrypted JSON
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('rate_periods');
        });
    }
};
