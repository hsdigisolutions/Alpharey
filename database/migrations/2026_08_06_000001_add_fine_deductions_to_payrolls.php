<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds fine_deductions to payrolls so employee-charged vehicle fines
 * are automatically deducted during the payroll calculation run.
 * Encrypted at rest (same pattern as advance_deductions / other_deductions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->text('fine_deductions')->nullable()->after('advance_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->dropColumn('fine_deductions');
        });
    }
};
