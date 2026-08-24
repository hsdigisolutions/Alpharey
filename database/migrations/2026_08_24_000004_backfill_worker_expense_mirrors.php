<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Safe transition for the two-gate worker-expense flow (Part B).
 *
 * Before this change, a worker-fuel mirror Expense was created approved=false and
 * the money flowed through PayrollService::pwaExpensesFor (which counted the
 * WorkerExpense). pwaExpensesFor now SKIPS worker expenses that have a mirror, so
 * without this backfill the reimbursement for already-approved fuel expenses
 * would drop out of the current (unpaid) month.
 *
 * Grandfather every EXISTING mirror (identified by a null employee_id — the old
 * service never set one): copy employee_id / vehicle_id / project_id from its
 * linked WorkerExpense, mark it reimbursable + employee-borne, and approve it
 * (it was already effectively approved under the old single-gate flow). It then
 * counts through reimbursementsFor exactly as before — no money vanishes, none
 * double-counts (pwaExpensesFor excludes it via auto_expense_id). New mirrors
 * created after this deploy already carry employee_id, so they are left alone
 * and correctly require the new admin final approval. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mirrors = DB::table('expenses')
            ->where('source', 'worker_fuel')
            ->whereNull('employee_id')
            ->get(['id', 'source_id']);

        foreach ($mirrors as $mirror) {
            $we = DB::table('worker_expenses')->where('id', $mirror->source_id)
                ->first(['employee_id', 'vehicle_id', 'project_id']);
            if ($we === null) {
                continue;
            }

            DB::table('expenses')->where('id', $mirror->id)->update([
                'employee_id' => $we->employee_id,
                'vehicle_id' => $we->vehicle_id,
                'project_id' => $we->project_id,
                'is_reimbursable' => true,
                'bearable_by' => 'employee',
                'deduct_from_salary' => false,
                'approved' => true,
                'approved_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Not reversed — grandfathered money state is intentionally kept.
    }
};
