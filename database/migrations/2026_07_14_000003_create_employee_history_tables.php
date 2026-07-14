<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-field salary change history — values encrypted like the source
        Schema::create('employee_salary_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('field', 40);
            $table->text('old_value')->nullable(); // encrypted
            $table->text('new_value')->nullable(); // encrypted
            $table->timestamp('created_at');

            $table->index(['employee_id', 'field']);
        });

        // Effective-dated wage rates (consumed by attendance/payroll phases)
        Schema::create('employee_wage_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('wage_type', 20);
            $table->text('rate'); // encrypted
            $table->date('effective_from');
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        // Notes timeline (Screen 06 Tab 5)
        Schema::create('employee_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('general'); // general|reminder|issue|call
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->dateTime('noted_at');
            $table->timestamps();

            $table->index(['employee_id', 'noted_at']);
        });

        // Call log (Screen 06 Tab 6 / Screen 13 later)
        Schema::create('employee_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('called_at');
            $table->text('remarks')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'called_at']);
            $table->index('follow_up_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_call_logs');
        Schema::dropIfExists('employee_notes');
        Schema::dropIfExists('employee_wage_rates');
        Schema::dropIfExists('employee_salary_history');
    }
};
