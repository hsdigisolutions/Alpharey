<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D — Daily Production Entry. A "Log work" action records a total quantity
 * split equally across the workers present on the project that day, as one
 * `task_progress` row per worker sharing a `batch_id` (so the multi-worker
 * entry is edited/deleted as a unit). `photo_path`/`photo_name` hold an optional
 * proof photo on the PRIVATE disk (server-set, not fillable); `logged_by` is who
 * recorded it. The task's `completed_quantity` is recomputed = Σ quantity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_progress', function (Blueprint $table): void {
            $table->uuid('batch_id')->nullable()->after('production_task_id')->index();
            $table->foreignId('logged_by')->nullable()->after('employee_id')->constrained('users')->nullOnDelete();
            $table->string('photo_path')->nullable()->after('notes');
            $table->string('photo_name')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('task_progress', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logged_by');
            $table->dropColumn(['batch_id', 'photo_path', 'photo_name']);
        });
    }
};
