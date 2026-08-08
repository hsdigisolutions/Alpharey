<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-designation rates on a project (Feature 2). One row per (project,
 * designation): what the CLIENT pays us and what WE pay the worker, at a given
 * rate type (per hour / per day / per meter). Attendance for a worker of that
 * designation on that project prices from these instead of the profile rate.
 *
 * Rates are NOT encrypted: they are project commercial terms, aggregated in SQL
 * for the P&L (same call as projects.client_hour_rate), not per-employee pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_designation_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('designation_id')->constrained()->cascadeOnDelete();
            $table->decimal('client_rate', 12, 2)->default(0);
            $table->decimal('worker_rate', 12, 2)->default(0);
            $table->string('rate_type', 20)->default('per_hour'); // App\Enums\ProjectRateType
            $table->timestamps();

            $table->unique(['project_id', 'designation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_designation_rates');
    }
};
