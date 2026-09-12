<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 2 (2026-09-12) — task rework after a client rejection.
 *
 * A rework batch (the crew redoing work the client rejected) must NOT bill the
 * client a second time: the client pays once for the accepted quantity, and the
 * redo is a pure cost/penalty to the company (its labour is already counted via
 * attendance). `is_rework` flags such a batch; the shared task-based revenue
 * reader and the completed_quantity recompute both exclude it. Server-set, NOT
 * fillable. Additive + defaulted false, so every existing row bills exactly as
 * before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_progress', function (Blueprint $table): void {
            $table->boolean('is_rework')->default(false)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('task_progress', function (Blueprint $table): void {
            $table->dropColumn('is_rework');
        });
    }
};
