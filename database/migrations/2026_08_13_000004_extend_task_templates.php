<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C — Task Templates. A template is a reusable production-task definition
 * (company-scoped) that prefills the bulk-add grid on a project. It carries the
 * same shape a task needs: category, unit, internal unit_price, a default
 * planned_quantity and an advisory weightage. `name`/`unit`/`description`/
 * `active` already exist from 2026_07_16_000003.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_templates', function (Blueprint $table): void {
            $table->string('category', 30)->default('other')->after('name');
            $table->decimal('unit_price', 12, 2)->nullable()->after('unit');
            $table->decimal('planned_quantity', 12, 2)->nullable()->after('unit_price');
            $table->decimal('weightage', 5, 2)->nullable()->after('planned_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table): void {
            $table->dropColumn(['category', 'unit_price', 'planned_quantity', 'weightage']);
        });
    }
};
