<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 8 (2026-09-13) — worker referral commission.
 *
 * A worker (the REFERRER) brings in a new worker (this row, the REFERRED). The
 * referrer then earns a commission from the referred worker's attendance, folded
 * automatically into the referrer's monthly payroll. One referrer per referred
 * worker; the terms live on the referred worker's own record.
 *
 *   referred_by_employee_id  who referred this worker (nullable FK employees)
 *   referral_rate_type       per_day | per_hour | per_month | one_time
 *   referral_amount          € per the rate type (a rate/term, not stored pay)
 *   referral_window_months   the referrer earns only for the first N months of
 *                            this worker's employment (from joining_date)
 *
 * All nullable → every existing worker is unreferred and unaffected. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('referred_by_employee_id')->nullable()
                ->after('team_leader_id')->constrained('employees')->nullOnDelete();
            $table->string('referral_rate_type', 20)->nullable()->after('referred_by_employee_id');
            $table->decimal('referral_amount', 14, 2)->nullable()->after('referral_rate_type');
            $table->unsignedSmallInteger('referral_window_months')->nullable()->after('referral_amount');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('referred_by_employee_id');
            $table->dropColumn(['referral_rate_type', 'referral_amount', 'referral_window_months']);
        });
    }
};
