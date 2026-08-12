<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Bearable By" (the legacy expense model): who ultimately bears a cost —
     * company / client / employee / unbillable. Only CLIENT-bearable costs feed
     * project invoicing; a company-card or employee cost the worker must repay is
     * flagged `deduct_from_salary` and comes off that month's payroll.
     *
     * `expense_deductions` is the payroll bucket those salary deductions land in
     * (kept separate from advances/fines so the payslip stays legible).
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('bearable_by', 20)->default('company')->after('is_reimbursable');
            $table->boolean('deduct_from_salary')->default(false)->after('bearable_by');
        });

        // Backfill: rows previously flagged reimbursable were employee-borne.
        DB::table('expenses')->where('is_reimbursable', true)->update(['bearable_by' => 'employee']);

        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('expense_deductions', 14, 2)->default(0)->after('fine_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['bearable_by', 'deduct_from_salary']);
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('expense_deductions');
        });
    }
};
