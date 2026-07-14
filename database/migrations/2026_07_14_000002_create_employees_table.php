<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REQUIREMENTS.md Screen 06 Tab 1. NIF, IBAN, bank name, and every
     * salary figure are encrypted at rest (text columns, encrypted casts);
     * nif_hash is the blind index that keeps NIF searchable (SECURITY.md §7).
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('employee_code', 20)->unique();

            // Personal
            $table->string('full_name');
            $table->text('nif')->nullable();              // encrypted
            $table->string('nif_hash', 64)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('address')->nullable();

            // Employment
            $table->string('department', 100)->nullable();
            $table->string('designation', 100)->nullable();
            $table->foreignId('team_leader_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('joining_date')->nullable();
            $table->date('leaving_date')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_contracted')->default(false);
            $table->string('default_check_in', 5)->default('09:00');
            $table->string('default_check_out', 5)->default('17:00');

            // Wage — encrypted amounts
            $table->string('wage_type', 20)->nullable(); // daily|hourly|monthly|per_meter
            $table->text('wage_rate')->nullable();       // encrypted
            $table->text('base_salary')->nullable();     // encrypted
            $table->text('daily_wage')->nullable();      // encrypted
            $table->text('per_meter_rate')->nullable();  // encrypted
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->string('payment_method', 30)->nullable();

            // Overtime
            $table->foreignId('overtime_policy_id')->nullable()->constrained('overtime_policies')->nullOnDelete();
            $table->foreignId('supervisor_overtime_policy_id')->nullable()->constrained('overtime_policies')->nullOnDelete();

            // Bank — encrypted
            $table->text('iban')->nullable();
            $table->text('bank_name')->nullable();

            // Other
            $table->boolean('has_driving_license')->default(false);
            $table->boolean('has_company_vehicle')->default(false);
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'active']);
            $table->index(['company_id', 'designation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
