<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screens 08/09 — Projects. Company-owned (BelongsToCompany) but the
     * client is shared. Cross-company deployment of workers arrives in
     * Phase 5; own-company worker rates land here.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('project_type', 100)->nullable();
            $table->string('status', 20)->default('active')->index();     // ProjectStatus
            $table->string('priority', 20)->default('medium');            // ProjectPriority
            $table->string('billing_type', 20)->nullable();               // BillingType
            $table->string('vat_rate', 20)->nullable();                   // VatRate (optional)

            // Contacts / roles (Resumen tab)
            $table->string('jefe_de_obra')->nullable();
            $table->string('jefe_phone', 30)->nullable();
            $table->string('jefe_email')->nullable();
            $table->string('encargado')->nullable();
            $table->string('seguridad')->nullable();
            $table->string('coordinator')->nullable();

            // Budget / dates
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->decimal('estimated_hours', 10, 2)->nullable();
            $table->decimal('estimated_meters', 10, 2)->nullable();

            // Billing rules / links
            $table->boolean('outsourced')->default(false);
            $table->foreignId('outsourced_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('google_drive_link')->nullable();
            $table->string('document_url')->nullable();
            $table->string('forma_de_pago', 100)->nullable();
            $table->string('fecha_de_cobro', 100)->nullable();
            $table->text('pre_invoice_rule')->nullable();
            $table->text('invoice_rule')->nullable();
            $table->text('due_rule')->nullable();
            $table->string('color_code', 20)->nullable();
            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'client_id']);
        });

        // Per-project wage override for own-company workers (Tab 2)
        Schema::create('project_employee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('wage_type', 20)->nullable();
            $table->text('project_rate')->nullable(); // encrypted (wage-sensitive)
            $table->timestamps();

            $table->unique(['project_id', 'employee_id']);
        });

        // Project alerts (Tab 8): budget / deadline / progress / custom
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('alert_type', 20)->default('custom');
            $table->string('title');
            $table->text('message')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->text('email_recipients')->nullable();
            $table->text('email_cc')->nullable();
            $table->string('status', 20)->default('pending'); // pending|sent|failed
            $table->timestamps();
        });

        // Immutable project notes / communication (Tab 8) — append-only per
        // the audit requirement (REQUIREMENTS.md Screen 09: "Notes cannot be
        // deleted once saved"). No updated_at; the model blocks update/delete.
        Schema::create('report_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('internal'); // internal|client_call|client_email|meeting|message
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->dateTime('noted_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['project_id', 'noted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_remarks');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('project_employee_rates');
        Schema::dropIfExists('projects');
    }
};
