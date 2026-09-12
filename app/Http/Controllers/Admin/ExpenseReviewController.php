<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Scopes\CompanyScope;
use App\Services\Expenses\ExpenseReceiptExport;
use App\Services\Vehicles\VehicleExpenseSyncService;
use App\Services\Workers\WorkerFuelExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function __construct(private readonly ExpenseReceiptExport $receipts) {}

    public function index(): Response
    {
        $expenses = Expense::query()->withoutGlobalScopes()
            ->where('review_status', 'in_review')
            // Cross-company SA tool: employee / project / vehicle are company-scoped,
            // so drop CompanyScope on the eager-loads or their names blank out when
            // the SA has a different company selected (same trap as the doc panel).
            ->with([
                'company:id,name,brand_name',
                'employee' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)->select('id', 'full_name', 'employee_code'),
                'project' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)->select('id', 'name', 'client_id'),
                'project.client:id,name',
                'vehicle' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)->select('id', 'plate_number', 'brand', 'model'),
                'category:id,name',
                'escalatedBy:id,name',
            ])
            ->orderBy('date')
            ->get()
            ->map(function (Expense $e): array {
                // The full receipt-detail row (BUG 2 component) + the queue extras.
                return array_merge($this->receipts->row($e), [
                    'company' => $e->company?->displayName(),
                    'vehicle' => $e->vehicle !== null
                        ? trim($e->vehicle->plate_number.' '.trim(($e->vehicle->brand ?? '').' '.($e->vehicle->model ?? '')))
                        : null,
                    'has_receipt' => $e->getAttribute('file_path') !== null,
                    'source' => $e->source,
                ]);
            })->all();

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
        // Cascade to the linked WorkerExpense + notify the worker (centralised so
        // the main Expenses tab and this queue behave identically). Money stays
        // on the mirror Expense's `approved` flag — status sync only.
        app(WorkerFuelExpenseService::class)->applyFinalDecisionToWorker($e, true, $e->approved_by);

        return back()->with('success', __('ui.expenses.approved'));
    }

    public function reject(Request $request, int $expense): RedirectResponse
    {
        $e = $this->resolveInReview($expense);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        $e->approved = false;
        $e->approved_by = Auth::id();
        $e->approved_at = now();
        $e->review_status = null;
        $e->save();

        app(WorkerFuelExpenseService::class)->applyFinalDecisionToWorker($e, false, $e->approved_by, $reason);

        return back()->with('success', __('ui.expenses.rejected'));
    }

    private function resolveInReview(int $expenseId): Expense
    {
        $e = Expense::query()->withoutGlobalScopes()->findOrFail($expenseId);
        abort_unless($e->review_status === 'in_review', 404);

        return $e;
    }
}
