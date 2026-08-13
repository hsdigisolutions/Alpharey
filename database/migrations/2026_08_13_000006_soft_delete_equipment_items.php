<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory rebuild — Phase A1. Equipment items soft-delete: a retired item
 * keeps its whole ledger + issue history forever (a hard delete would cascade
 * that away). The delete is guarded server-side so an item with kit still out
 * with a worker or on a site can never be removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
