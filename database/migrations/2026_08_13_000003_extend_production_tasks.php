<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production Tasks — the internal planned-vs-actual tracker (2026-08-13). The
 * Phase-4 scaffolding table only had name/unit/estimated_quantity/status; this
 * fills in the real fields. `unit_price` is an INTERNAL cost rate, never client
 * billing (only approved measurements bill the client). `completed_quantity` is
 * recomputed from task_progress (Phase D); weightage is advisory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->string('category', 30)->default('other')->after('name');
            $table->string('house_number', 100)->nullable()->after('category');
            $table->decimal('unit_price', 12, 2)->default(0)->after('unit');
            $table->decimal('planned_quantity', 12, 2)->default(0)->after('unit_price');
            $table->decimal('completed_quantity', 12, 2)->default(0)->after('planned_quantity');
            $table->decimal('weightage', 5, 2)->default(0)->after('completed_quantity');
            $table->text('notes')->nullable()->after('status');
        });

        // The scaffolding column is superseded by planned_quantity.
        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->dropColumn('estimated_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->decimal('estimated_quantity', 12, 2)->nullable();
            $table->dropColumn([
                'category', 'house_number', 'unit_price',
                'planned_quantity', 'completed_quantity', 'weightage', 'notes',
            ]);
        });
    }
};
