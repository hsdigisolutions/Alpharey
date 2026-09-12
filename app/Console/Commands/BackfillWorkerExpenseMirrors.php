<?php

namespace App\Console\Commands;

use App\Enums\WorkerExpenseStatus;
use App\Models\WorkerExpense;
use App\Services\Workers\WorkerFuelExpenseService;
use Illuminate\Console\Command;

/**
 * Item 5 cutover — the Worker Expenses admin tab is gone; every worker PWA
 * submission now mints its mirror Expense at submission time. This backfills the
 * in-flight PENDING worker expenses that were submitted before the change and so
 * have no mirror yet, so they appear in the regular Expenses tab (pending) for
 * review.
 *
 * ONLY pending rows are touched. An APPROVED legacy row without a mirror is paid
 * through PayrollService::pwaExpensesFor (which counts un-mirrored approved rows);
 * minting an UNAPPROVED mirror for it would drop it out of that path and unpay the
 * worker — so those are deliberately left alone. Idempotent (skips any that
 * already have a mirror).
 */
class BackfillWorkerExpenseMirrors extends Command
{
    protected $signature = 'worker-expenses:backfill-mirrors {--dry-run : List what would change without writing}';

    protected $description = 'Mint the mirror Expense for PENDING worker expenses that have none (Item 5 cutover).';

    public function handle(WorkerFuelExpenseService $service): int
    {
        $pending = WorkerExpense::query()->withoutGlobalScopes()
            ->where('status', WorkerExpenseStatus::Pending->value)
            ->whereNull('auto_expense_id')
            ->get();

        $this->info("Pending worker expenses without a mirror: {$pending->count()}");

        $minted = 0;
        foreach ($pending as $we) {
            if ($this->option('dry-run')) {
                $this->line("  would mirror WorkerExpense #{$we->id} ({$we->amount})");

                continue;
            }

            $mirror = $service->mirrorOnSubmission($we);
            if ($mirror !== null) {
                $minted++;
                $this->line("  WorkerExpense #{$we->id} -> Expense #{$mirror->id}");
            }
        }

        $this->info($this->option('dry-run')
            ? 'Dry run — nothing written.'
            : "Minted {$minted} mirror expense(s).");

        return self::SUCCESS;
    }
}
