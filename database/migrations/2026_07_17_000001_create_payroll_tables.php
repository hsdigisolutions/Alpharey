<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 12 — Payroll. Company-owned monthly runs computed from the wage
     * snapshots frozen on attendance (Phase 4), so a later raise never
     * rewrites a historical payroll.
     *
     * Salary figures are encrypted at rest (text columns + encrypted casts),
     * matching the employees table convention (SECURITY.md §7). Amounts that
     * must be aggregated in SQL are NOT encrypted — only per-employee pay is.
     */
    public function up(): void
    {
        Schema::create('advance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advance_category_id')->nullable()->constrained()->nullOnDelete();
            $table->text('amount');                       // encrypted
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending'); // AdvanceStatus
            $table->date('request_date');
            $table->date('payment_date')->nullable();
            // The payroll month this advance is deducted from ('YYYY-MM')
            $table->string('payroll_month', 7)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'payroll_month']);
        });

        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);                   // 'YYYY-MM'

            // Attendance inputs (aggregates — plain, needed for reporting)
            $table->decimal('attendance_days', 6, 2)->default(0);
            $table->decimal('attendance_hours', 8, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);

            // Wage basis frozen at calculation time
            $table->string('wage_type', 20)->nullable();  // WageType
            $table->text('wage_rate')->nullable();        // encrypted
            $table->text('base_salary')->nullable();      // encrypted

            // Money — encrypted per-employee pay
            $table->text('days_amount')->nullable();      // encrypted
            $table->text('hours_amount')->nullable();     // encrypted
            $table->text('overtime_pay')->nullable();     // encrypted
            $table->text('reimbursements')->nullable();   // encrypted
            $table->text('project_expenses')->nullable(); // encrypted — worker project expenses
            $table->text('gross_pay')->nullable();        // encrypted
            $table->text('advance_deductions')->nullable(); // encrypted
            $table->text('other_deductions')->nullable();   // encrypted
            $table->text('manual_additions')->nullable();   // encrypted
            $table->text('net_amount')->nullable();         // encrypted

            $table->string('status', 20)->default('pending'); // PayrollStatus
            $table->string('payment_method', 30)->nullable();
            $table->date('paid_at')->nullable();
            $table->text('notes')->nullable();
            // Option A deployment note lines ("Deployed to X — cost transferred")
            $table->json('deployment_notes')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            // One payroll row per employee per month
            $table->unique(['employee_id', 'month']);
            $table->index(['company_id', 'month']);
            $table->index(['company_id', 'status']);
        });

        /**
         * A locked month rejects any attendance/payroll edit system-wide.
         * Company-owned so one company can close its books independently.
         */
        Schema::create('locked_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);                   // 'YYYY-MM'
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locked_periods');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('advances');
        Schema::dropIfExists('advance_categories');
    }
};
