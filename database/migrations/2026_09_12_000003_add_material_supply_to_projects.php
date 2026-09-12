<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 3 (2026-09-12) — project-level "who supplies the materials" label.
 *
 * Purely descriptive metadata (App\Enums\MaterialSupply): client_included /
 * client_separate / company, null = not specified. It does NOT feed P&L,
 * invoicing or payroll — those keep running off each expense's `bearable_by`.
 * Additive + nullable, so every existing project is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('material_supply', 20)->nullable()->after('billing_type');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('material_supply');
        });
    }
};
