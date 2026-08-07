<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manual wage override lets the clerk type any amount for a day; this records
 * WHY, so an unusual figure is never unexplained on a payroll run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->string('override_reason', 255)->nullable()->after('manual_wage_override');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn('override_reason');
        });
    }
};
