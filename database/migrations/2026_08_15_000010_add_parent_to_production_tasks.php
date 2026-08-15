<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production tasks gain an optional parent (one level deep only). A top-level
 * task has parent_task_id = null; a sub-task points at its parent. Deleting a
 * parent cascades to its sub-tasks (and their progress rows). Server-set on
 * create — never mass-assignable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->foreignId('parent_task_id')->nullable()->after('project_id')
                ->constrained('production_tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_task_id');
        });
    }
};
