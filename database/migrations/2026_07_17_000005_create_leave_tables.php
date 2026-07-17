<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 22 — Leave Management.
     *
     * The legacy schema hung leaves off `user_id`. This one hangs them off
     * `employee_id`, deliberately: approved leave has to write attendance rows
     * (the blue cells) and therefore reach payroll, and both of those are keyed
     * on employees — a user is a login, not a member of the workforce. Nothing
     * links the two tables in either schema, so the importer resolves legacy
     * user → employee by name and reports what it cannot match
     * (DATA_MIGRATION.md §3.7).
     */
    public function up(): void
    {
        /**
         * Categories are the configurable list behind Settings (Screen 26).
         * company_id is nullable: NULL is a group-wide default (the 8 seeded
         * ones), a value is a company's own addition — same shape as
         * expense_categories.
         */
        Schema::create('leave_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('default_allocation', 6, 1)->default(0);
            // Unpaid leave still books attendance, but must not pay for the day.
            $table->boolean('is_paid')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_category_id')->constrained()->cascadeOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            // decimal(5,1): half days are real (medio día de asuntos propios)
            $table->decimal('total_days', 5, 1)->default(0);
            $table->text('reason')->nullable();

            $table->string('status', 20)->default('pending'); // LeaveStatus

            // Review — set directly, not mass-assignable (the Measurement rule)
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // Justificante (médico, etc.) on the private disk
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'start_date']);
        });

        /**
         * One row per employee × category × year. `remaining` is deliberately
         * NOT stored — it is allocated + carried_over − used − pending, and a
         * stored copy is just a second truth waiting to drift.
         */
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_category_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');

            $table->decimal('allocated', 6, 1)->default(0);
            $table->decimal('used', 6, 1)->default(0);
            $table->decimal('pending', 6, 1)->default(0);
            $table->decimal('carried_over', 6, 1)->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'leave_category_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leaves');
        Schema::dropIfExists('leave_categories');
    }
};
