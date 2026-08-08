<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project profitability (P&L) needs three figures the projects table never
 * carried: what we bill the CLIENT per hour / per m² (revenue side), and what
 * an OUTSOURCED project costs us (its own cost line, replacing our own labour).
 *
 * All nullable — a project with no client rate simply reports zero revenue on
 * the hourly/per-meter methods (fixed/milestone projects bill from invoices and
 * ignore these). None are sensitive pay data, so they are plain decimals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->decimal('client_hour_rate', 10, 2)->nullable()->after('estimated_meters');
            $table->decimal('client_meter_rate', 10, 2)->nullable()->after('client_hour_rate');
            $table->decimal('outsource_cost', 14, 2)->nullable()->after('outsourced_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['client_hour_rate', 'client_meter_rate', 'outsource_cost']);
        });
    }
};
