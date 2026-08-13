<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory rebuild — Phase B. Serial numbers for expensive kit. Per the
 * confirmed model, one item record IS one serialized unit (DR-001 = that
 * specific drill, quantity 1), so this is just an optional field on the item —
 * no schema fork, quantity tracking works unchanged. Uniqueness is enforced at
 * the form layer (unique per company when filled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table): void {
            $table->string('serial_number', 60)->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table): void {
            $table->dropColumn('serial_number');
        });
    }
};
