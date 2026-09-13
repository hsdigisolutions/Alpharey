<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item 8 follow-up (2026-09-13) — the referral WINDOW is anchored at the date the
 * referral was SET UP, not the referred worker's joining date (most real workers
 * have no joining_date, and retroactively-added referrals on existing workers
 * should still earn for their full window from the moment they are configured).
 *
 * Server-set only (never fillable): a model hook stamps it when a referral is
 * first configured and clears it when removed. Existing configured referrals are
 * backfilled to "now" so their window starts from this feature going live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->timestamp('referral_started_at')->nullable()->after('referral_window_months');
        });

        // Backfill already-configured referrals so their window starts now.
        DB::table('employees')
            ->whereNotNull('referred_by_employee_id')
            ->whereNotNull('referral_rate_type')
            ->whereNull('referral_started_at')
            ->update(['referral_started_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('referral_started_at');
        });
    }
};
