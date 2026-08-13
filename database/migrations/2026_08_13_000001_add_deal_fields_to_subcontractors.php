<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The thaekedar DEAL (confirmed business model 2026-08-13): what the client
 * pays us, the fixed budget agreed with the subcontractor, and who bears the
 * project expenses (Scenario A: thaekedar, out of his budget; Scenario B: us,
 * reducing our own profit). Our profit = client_amount − agreed_budget
 * (− our expenses in Scenario B) — always derived, never stored.
 *
 * Both money columns are nullable: legacy records without a budget fall back
 * to payments-as-cost in the P&L until an admin fills the deal in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcontractors', function (Blueprint $table): void {
            $table->decimal('client_amount', 12, 2)->nullable()->after('project_id');
            $table->decimal('agreed_budget', 12, 2)->nullable()->after('client_amount');
            $table->string('expense_responsibility', 20)->default('thaekedar')->after('agreed_budget');
        });
    }

    public function down(): void
    {
        Schema::table('subcontractors', function (Blueprint $table): void {
            $table->dropColumn(['client_amount', 'agreed_budget', 'expense_responsibility']);
        });
    }
};
