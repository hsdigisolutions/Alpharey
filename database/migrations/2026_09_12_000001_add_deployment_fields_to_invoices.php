<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inter-company deployment invoices (2026-09-12). When a deployment completes,
 * the HOME company issues a real invoice to the HOST for the deployed labour.
 *
 * - counterparty_company_id: the OTHER company being billed (the host), instead
 *   of an external Client — keeps the shared Clients pool clean.
 * - deployment_charge_id: 1:1 link to the DeploymentCharge that calculated the
 *   amount; its presence is what marks an invoice as a deployment invoice.
 *
 * Both are server-set (never fillable) and nullable — every existing and every
 * normal client/vendor invoice is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('counterparty_company_id')->nullable()->after('vendor_id')
                ->constrained('companies')->nullOnDelete();
            $table->foreignId('deployment_charge_id')->nullable()->after('counterparty_company_id')
                ->constrained('deployment_charges')->nullOnDelete();
            $table->unique('deployment_charge_id'); // at most one invoice per charge
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['deployment_charge_id']);
            $table->dropConstrainedForeignId('deployment_charge_id');
            $table->dropConstrainedForeignId('counterparty_company_id');
        });
    }
};
