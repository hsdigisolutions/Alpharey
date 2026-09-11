<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task-based billing — each production task gets a CLIENT rate (what the client
 * is billed per unit), kept SEPARATE from `unit_price` (the internal cost
 * estimate). Revenue for a task_based project = Σ (task-progress quantity ×
 * task.client_rate). Additive + nullable: existing tasks are unaffected and a
 * task with no client_rate simply bills nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->decimal('client_rate', 14, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->dropColumn('client_rate');
        });
    }
};
