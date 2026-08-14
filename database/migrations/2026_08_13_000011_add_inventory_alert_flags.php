<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory rebuild — Phase F. "Notified" guards so the daily notifications:scan
 * fires each alert ONCE, not every morning (mirrors invoices.overdue_notified_at
 * and vehicle_sessions.overdue_alerted). All server-set, not fillable.
 *
 * - low_stock_notified_at is cleared by the movement service when stock recovers
 *   above the minimum, so the alert re-arms for the next dip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table): void {
            $table->timestamp('low_stock_notified_at')->nullable();
        });

        Schema::table('employee_equipment_issues', function (Blueprint $table): void {
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamp('ppe_expiry_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table): void {
            $table->dropColumn('low_stock_notified_at');
        });
        Schema::table('employee_equipment_issues', function (Blueprint $table): void {
            $table->dropColumn(['overdue_notified_at', 'ppe_expiry_notified_at']);
        });
    }
};
