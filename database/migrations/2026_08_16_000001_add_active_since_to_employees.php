<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHEN an employee was (re)activated, so computed absences count from
 * that date rather than from their original joining date.
 *
 * An employee who is deactivated ("not working with us now") must not accrue
 * absences; when they are reactivated later, the calendar should start counting
 * from the reactivation day — not retroactively fill the inactive gap with red.
 *
 * Server-set only (never fillable) — the Employee model stamps it on the
 * active=false → true transition. Null for existing rows: an already-active
 * employee keeps counting from joining_date (unchanged behaviour); an inactive
 * one accrues no absences regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->date('active_since')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('active_since');
        });
    }
};
