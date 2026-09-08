<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice-based deployment settlement (2026-09). The cross-charge already exists
 * (deployment_charges) and spans both companies; this adds a SETTLEMENT layer on
 * top — a "the host has paid the home company back" status + timeline — WITHOUT
 * touching the internal_deployment Expense (which stays locked/unapproved by
 * Item A so the labour is never double-counted in project P&L).
 *
 * - settlement_status: unpaid → paid (server-set; not the P&L approval flow).
 * - invoiced_at: stamped when the deployment COMPLETES (the charge locks) — that
 *   is when the amount becomes payable/settleable (before that it is accruing).
 * - paid_at / paid_by: stamped when the HOST admin marks it paid.
 *
 * All additive + nullable (bar the defaulted status), so existing rows are
 * simply "unpaid, not yet invoiced".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployment_charges', function (Blueprint $table): void {
            $table->string('settlement_status', 20)->default('unpaid')->after('status');
            $table->timestamp('invoiced_at')->nullable()->after('settlement_status');
            $table->timestamp('paid_at')->nullable()->after('invoiced_at');
            $table->foreignId('paid_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deployment_charges', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['settlement_status', 'invoiced_at', 'paid_at']);
        });
    }
};
