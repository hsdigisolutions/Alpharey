<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing period as a structured DATE RANGE (2026-09-13).
 *
 * A long-running project is invoiced MONTH BY MONTH; each invoice needs to say
 * which period of work/cost it bills for (e.g. 1–31 Aug), distinct from the
 * invoice_date (when the document was issued) and the due_date (when payment is
 * expected). The legacy free-text `billing_period` (string) stays as a read-only
 * fallback for imported invoices; new invoices use this structured range, which
 * powers the per-project invoiced-periods history + overlap warning.
 *
 * Both nullable — a one-off invoice may carry no period. Record-keeping/display
 * only; it does NOT change any P&L calculation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->date('billing_period_start')->nullable()->after('billing_period');
            $table->date('billing_period_end')->nullable()->after('billing_period_start');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['billing_period_start', 'billing_period_end']);
        });
    }
};
