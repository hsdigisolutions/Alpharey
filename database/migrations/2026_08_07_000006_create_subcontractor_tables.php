<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subcontractor (thaekedar) management: a subcontractor engaged on a project,
 * the workers they bring, and the schedule of payments to them. Marking a
 * payment paid auto-creates an Expense on the subcontractor's company + project.
 *
 * Company-owned (tenancy on `subcontractors`); workers and payments hang off
 * the subcontractor and are reached through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcontractors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('nif', 20)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('subcontractor_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcontractor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_our_employee')->default(false);
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('days_worked', 8, 2)->default(0);
            $table->decimal('agreed_rate', 10, 2)->default(0);   // €/day
            $table->decimal('total_agreed', 12, 2)->default(0);  // days × rate
            $table->string('payment_status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('subcontractor_id');
        });

        Schema::create('subcontractor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcontractor_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('payment_number');
            $table->date('payment_date')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending');
            // The auto-created Gasto when this payment is marked paid.
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('subcontractor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subcontractor_payments');
        Schema::dropIfExists('subcontractor_workers');
        Schema::dropIfExists('subcontractors');
    }
};
