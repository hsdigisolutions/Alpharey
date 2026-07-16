<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REQUIREMENTS.md §3 — cross-company employee deployment. A deployment
     * spans TWO companies (home + host), so this table is NOT tenancy-scoped
     * by a single company_id; visibility is "home OR host OR Super Admin",
     * enforced in the controller.
     */
    public function up(): void
    {
        Schema::create('employee_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('home_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('host_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('deployment_start');
            $table->date('deployment_end')->nullable(); // open-ended if null
            $table->string('billing_method', 20)->default('option_a'); // BillingMethod
            $table->decimal('rate_during_deployment', 10, 2)->nullable();
            $table->string('rate_type', 20)->default('hourly'); // DeploymentRateType
            $table->decimal('split_pct', 5, 2)->default(100); // host share (Option A/C)
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active'); // DeploymentStatus
            $table->timestamps();

            $table->index(['host_company_id', 'status']);
            $table->index(['home_company_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index(['project_id']);
        });

        /**
         * Option A cross-charge ledger: what the host company owes the home
         * company for using the deployed employee. Generated when a
         * deployment completes (or regenerated); the cross-company cost
         * report (Phase 8) reads from here. Phase 6 links these into the
         * expense/invoice system.
         */
        Schema::create('deployment_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('home_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('host_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('units', 10, 2)->default(0);   // hours or days billed
            $table->string('rate_type', 20);
            $table->decimal('rate', 10, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);  // units × rate × split%
            $table->string('status', 20)->default('pending'); // pending | settled
            $table->timestamps();

            $table->index(['host_company_id', 'status']);
            $table->index(['home_company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_charges');
        Schema::dropIfExists('employee_deployments');
    }
};
