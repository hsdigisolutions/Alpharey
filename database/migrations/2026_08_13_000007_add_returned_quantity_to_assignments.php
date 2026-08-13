<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory rebuild — Phase A2. Project assignments now move stock through the
 * ledger (assign = out of the store, return = back in), and a site can hand kit
 * back in parts, so the assignment needs a `returned_quantity` tail exactly like
 * a worker issue. Server-set only (StockMovementService owns it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_project_assignments', function (Blueprint $table): void {
            $table->decimal('returned_quantity', 12, 2)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_project_assignments', function (Blueprint $table): void {
            $table->dropColumn('returned_quantity');
        });
    }
};
