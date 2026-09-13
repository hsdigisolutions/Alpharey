<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 8 (2026-09-13) — the referral-commission line on a payslip.
 *
 * The referrer's monthly payroll gains a `referral_commission` earnings line =
 * Σ over the workers they referred (within each referral's window, both parties
 * active) of the accrued amount. Encrypted at rest like every other pay figure;
 * a '0' default so existing rows read zero until recomputed (healUndecryptable
 * tolerates the plaintext default). Additive to gross; nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->text('referral_commission')->nullable()->after('project_expenses');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->dropColumn('referral_commission');
        });
    }
};
