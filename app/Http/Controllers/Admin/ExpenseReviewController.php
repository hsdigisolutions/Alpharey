<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkerExpenseStatus;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\WorkerExpense;
use App\Services\Vehicles\VehicleExpenseSyncService;
use App\Services\Workers\WorkerFuelExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Part C — "Expenses awaiting review". The Super-Admin-only queue where an
 * expense escalated by a manager or an admin lands. The Super Admin's decision
 * here is the FINAL call: approve = the final approval (releases the money into
 * payroll + auto-creates the vehicle row), reject = unapproved, counts nowhere.
 *
 * Super-Admin-only via the `super_admin` route middleware; cross-company by
 * design (the queue is a group-wide SA tool), so the tenant scope is dropped.
 */
class ExpenseReviewController extends Controller
{
    public function index(): Response
    {
        $expenses = Expense::query()->withoutGlobalScopes()
            ->where('review_status', 'in_review')
            ->with([
                'company:id,name,brand_name',
                'employee:id,full_name',
                'vehicle:id,plate_number,brand,model',
                'category:id,name',
            ])
            ->orderBy('date')
            ->get()
            ->map(fn (Expense $e): array => [
                'id' => $e->id,
                'company' => $e->company?->displayName(),
                'date' => $e->date->toDateString(),
                'amount' => (float) $e->total,
                'category' => $e->category?->name,
                'employee' => $e->employee?->full_name,
                'vehicle' => $e->vehicle !== null
                    ? trim($e->vehicle->plate_number.' '.trim(($e->vehicle->brand ?? '').' '.($e->vehicle->model ?? '')))
                    : null,
                'notes' => $e->notes,
                'has_receipt' => $e->getAttribute('file_path') !== null,
                'source' => $e->source,
            ])->all();

        return Inertia::render('Admin/ExpenseReview', ['expenses' => $expenses]);
    }

    public function approve(int $expense): RedirectResponse
    {
        $e = $this->resolveInReview($expense);

        $e->approved = true;
        $e->approved_by = Auth::id();
        $e->approved_at = now();
        $e->review_status = null;
        $e->save();

        app(VehicleExpenseSyncService::class)->syncOnFinalApproval($e);
        $this->syncLinkedWorkerExpense($e, true);

        return back()->with('success', __('ui.expenses.approved'));
    }

    public function reject(int $expense): RedirectResponse
    {
        $e = $this->resolveInReview($expense);

        $e->approved = false;
        $e->approved_by = Auth::id();
        $e->approved_at = now();
        $e->review_status = null;
        $e->save();

        $this->syncLinkedWorkerExpense($e, false);

        return back()->with('success', __('ui.expenses.rejected'));
    }

    private function resolveInReview(int $expenseId): Expense
    {
        $e = Expense::query()->withoutGlobalScopes()->findOrFail($expenseId);
        abort_unless($e->review_status === 'in_review', 404);

        return $e;
    }

    /** Keep a worker-sourced expense's WorkerExpense status in step with the SA decision. */
    private function syncLinkedWorkerExpense(Expense $expense, bool $approved): void
    {
        $isWorkerMirror = in_array(
            $expense->source,
            [WorkerFuelExpenseService::SOURCE, WorkerFuelExpenseService::SOURCE_GENERAL],
            true,
        );
        if (! $isWorkerMirror || $expense->source_id === null) {
            return;
        }

        $we = WorkerExpense::query()->withoutGlobalScopes()->find($expense->source_id);
        if ($we === null) {
            return;
        }

        $we->status = $approved ? WorkerExpenseStatus::Approved : WorkerExpenseStatus::Rejected;
        $we->save();
    }
}
