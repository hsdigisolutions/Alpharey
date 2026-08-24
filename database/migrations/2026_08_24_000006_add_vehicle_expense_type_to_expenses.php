<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part D — direct vehicle expense entry. `vehicle_expense_type` (fine /
 * maintenance / fuel) tells VehicleExpenseSyncService which vehicle_* table to
 * write to on final approval — for both admin-created direct entries and the
 * worker-PWA fuel mirror. Additive + nullable. Existing worker-fuel mirrors are
 * back-filled to 'fuel'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('vehicle_expense_type', 20)->nullable()->after('vehicle_id');
        });

        // Existing worker-fuel mirrors are fuel by definition.
        DB::table('expenses')->where('source', 'worker_fuel')->update(['vehicle_expense_type' => 'fuel']);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn('vehicle_expense_type');
        });
    }
};
