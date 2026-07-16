<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 10 Gastos + Screen 09 Tab 6 — supplier/worker costs.
     *
     * An expense tagged to BOTH an employee and a project is a "worker project
     * expense" and is pulled into that employee's payroll for the month
     * (REQUIREMENTS.md Screen 12) — hence the employee_id + project_id pair and
     * the (employee_id, date) index the payroll engine reads.
     */
    public function up(): void
    {
        Schema::create('company_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('last_four', 4)->nullable();
            $table->foreignId('holder_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40)->nullable();
            $table->string('type', 20)->default('factura'); // ExpenseType
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_card_id')->nullable()->constrained()->nullOnDelete();

            $table->date('date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->string('vat_rate', 20)->nullable();    // VatRate enum value; null = No aplica
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            $table->string('payment_method', 30)->nullable();
            $table->string('payment_status', 20)->default('unpaid'); // PaymentStatus
            $table->date('payment_date')->nullable();

            // Approval — set directly, not mass-assignable (like Measurement)
            $table->boolean('approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Optional receipt/attachment on the private disk
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();

            // True when this cost is reimbursed to the worker through payroll
            $table->boolean('is_reimbursable')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['employee_id', 'date']); // worker project expenses -> payroll
            $table->index(['project_id']);
            $table->index(['vendor_id']);
        });

        Schema::create('expense_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        /**
         * Screen 19 — commission per employee × project × invoice. The original
         * amount is kept alongside the adjusted one so an adjustment always
         * shows what it changed and why.
         */
        Schema::create('commission_report_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('month', 7);                    // 'YYYY-MM'

            $table->decimal('base_amount', 14, 2)->default(0);
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->decimal('original_amount', 14, 2)->default(0);
            $table->decimal('adjusted_amount', 14, 2)->nullable();
            $table->text('adjustment_reason')->nullable();

            $table->string('status', 20)->default('draft'); // CommissionStatus
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'month']);
            $table->index(['employee_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_report_entries');
        Schema::dropIfExists('expense_line_items');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('company_cards');
    }
};
