<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle-expense flow (Parts B/D/E). Additive schema only — no behaviour change:
 *  - expenses.vehicle_id: the structured vehicle link that was missing (vehicle
 *    identity previously survived only as free text in the notes).
 *  - vehicle_fuel_records.expense_id + vehicle_maintenance_histories.expense_id:
 *    so an approved vehicle expense can auto-create the matching vehicle_* row
 *    linked back to its expense (vehicle_fines.expense_id already exists).
 *  - user_module_permissions.can_approve_final: the new Admin-level final
 *    approval gate (expenses.approve_final), separate from manager-level approve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->nullable()->after('project_id')
                ->constrained('vehicles')->nullOnDelete();
        });

        Schema::table('vehicle_fuel_records', function (Blueprint $table): void {
            $table->foreignId('expense_id')->nullable()->after('vehicle_id')
                ->constrained('expenses')->nullOnDelete();
        });

        Schema::table('vehicle_maintenance_histories', function (Blueprint $table): void {
            $table->foreignId('expense_id')->nullable()->after('vehicle_id')
                ->constrained('expenses')->nullOnDelete();
        });

        Schema::table('user_module_permissions', function (Blueprint $table): void {
            $table->boolean('can_approve_final')->default(false)->after('can_approve');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_id');
        });
        Schema::table('vehicle_fuel_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('expense_id');
        });
        Schema::table('vehicle_maintenance_histories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('expense_id');
        });
        Schema::table('user_module_permissions', function (Blueprint $table): void {
            $table->dropColumn('can_approve_final');
        });
    }
};
