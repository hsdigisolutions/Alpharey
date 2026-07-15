<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Basic production-task tables (structure in Phase 4; the full task
     * board surfaces through project views in a later pass). Company-owned.
     */
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit', 20)->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('production_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit', 20)->nullable();
            $table->decimal('estimated_quantity', 12, 2)->nullable();
            $table->string('status', 20)->default('open'); // open | in_progress | done
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('task_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->decimal('quantity', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('production_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_progress');
        Schema::dropIfExists('production_tasks');
        Schema::dropIfExists('task_templates');
    }
};
