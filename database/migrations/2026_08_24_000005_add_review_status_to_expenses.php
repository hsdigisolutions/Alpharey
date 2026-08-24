<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part C — "Send to review". Either approval level (manager on Worker Expenses,
 * admin in the Expenses tab) can escalate an expense to a Super-Admin-only
 * review queue instead of approving/rejecting. review_status = 'in_review' puts
 * the Expense in that queue; the Super Admin's decision there is the FINAL call
 * (approve = the final approval, clears review; reject clears review, unapproved).
 * Additive + nullable — a null review_status is the normal (non-review) state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('review_status', 20)->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn('review_status');
        });
    }
};
