<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 24 — Measurements. Company-owned. Approved measurements feed
     * project billing (Phase 6). Approve/reject workflow.
     */
    public function up(): void
    {
        Schema::create('measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 20)->nullable();
            $table->string('measurement_type', 20)->default('length'); // MeasurementType
            $table->boolean('approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['project_id', 'approved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurements');
    }
};
