<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 10 — Invoices (Ventas / Gastos on one screen) + payments.
     *
     * Invoice money is NOT encrypted: these are company books, aggregated and
     * filtered in SQL for reports and totals. Only per-employee pay is
     * encrypted (see the payrolls table).
     *
     * VAT is nullable with NO default — DECISIONS.md: blank by default, never
     * 21%. The spec's "default 21%" is explicitly overridden.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('type', 20);                    // InvoiceType: sale|expense
            $table->string('sub_type', 20)->default('final'); // InvoiceSubType: pre|final

            // Sale → client; Expense → vendor. Both optional-by-column, but
            // enforced per type in the Form Request.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('billing_type', 30)->nullable();
            $table->string('billing_period', 40)->nullable();

            // Totals — all computed server-side from the line items
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->nullable();  // blank default, per DECISIONS.md
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->string('discount_type', 10)->nullable(); // DiscountType
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('retention_percent', 5, 2)->nullable();
            $table->decimal('retention_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);

            $table->string('status', 20)->default('draft');        // InvoiceStatus
            $table->string('payment_status', 20)->default('unpaid'); // PaymentStatus
            $table->date('payment_date')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'type', 'invoice_date']);
            $table->index(['company_id', 'payment_status']);
            $table->index(['project_id']);
        });

        Schema::create('invoice_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('payment_date');
            $table->string('payment_method', 30)->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'payment_date']);
        });

        /**
         * Per-project invoice reminder schedule (Screen 10, bottom section).
         * Sending is wired in Phase 8 alongside the other scheduled mail.
         */
        Schema::create('invoice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);                   // 'YYYY-MM'
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedSmallInteger('reminder_days')->default(0);
            $table->json('reminder_emails')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reminders');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_line_items');
        Schema::dropIfExists('invoices');
    }
};
