<?php

namespace App\Http\Controllers;

use App\Enums\NotificationType;
use App\Enums\WorkerExpenseStatus;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Models\Employee;
use App\Models\WorkerExpense;
use App\Rules\OwnCompanyEmployee;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Workers\WorkerFuelExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CRM admin: review and approve / reject worker-submitted expenses.
 *
 * Gate: `expenses.approve` for approve/reject; `expenses.view` for index.
 * Receipt download: `expenses.view` required, audited.
 */
class WorkerExpenseAdminController extends Controller
{
    use ResolvesCompanyContext;

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('expenses.view');

        $companyId = $this->contextCompanyId();

        $expenses = WorkerExpense::query()
            ->where('company_id', $companyId)
            ->with(['employee:id,full_name,employee_code', 'approver:id,name'])
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (WorkerExpense $e) => [
                'id' => $e->id,
                'employee' => $e->employee?->only('id', 'full_name', 'employee_code'),
                'date' => $e->date->toDateString(),
                'amount' => $e->amount,
                'category' => $e->category,
                'description' => $e->description,
                'status' => $e->status->value,
                'approved_by' => $e->approver?->name,
                'approved_at' => $e->approved_at?->toDateTimeString(),
                'rejection_reason' => $e->rejection_reason,
                'has_receipt' => (bool) $e->receipt_path,
                'payroll_id' => $e->payroll_id,
            ]);

        return Inertia::render('WorkerExpenses/Index', [
            'expenses' => $expenses,
            // For the admin "New worker expense" form (add on behalf of a worker).
            'employees' => Employee::query()
                ->where('company_id', $companyId)
                ->where('active', true)
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_code'])
                ->map(fn ($e) => ['id' => $e->id, 'full_name' => $e->full_name, 'employee_code' => $e->employee_code])
                ->all(),
            'categories' => ['fuel', 'transport', 'materials', 'tools', 'food', 'other'],
            'can' => [
                'approve' => Gate::allows('expenses.approve'),
                'create' => Gate::allows('expenses.create'),
            ],
        ]);
    }

    /**
     * Admin adds a worker expense from the CRM (not the phone app), on behalf of
     * a worker. Entered deliberately by an authorised admin, so it is approved on
     * the spot — it then flows into that month's payroll reimbursements like any
     * approved worker expense.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('expenses.create');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', new OwnCompanyEmployee],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'category' => ['required', 'string', Rule::in(['fuel', 'transport', 'materials', 'tools', 'food', 'other'])],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $expense = new WorkerExpense([
            'employee_id' => $validated['employee_id'],
            'date' => $validated['date'],
            'amount' => $validated['amount'],
            'category' => $validated['category'],
            'description' => $validated['description'] ?? '',
        ]);
        // company_id is filled by BelongsToCompany; status is not fillable.
        $expense->status = WorkerExpenseStatus::Approved;
        $expense->approved_by = Auth::id();
        $expense->approved_at = now();
        $expense->save();

        // A fuel expense mirrors into the company expenses module on approval.
        app(WorkerFuelExpenseService::class)->syncApproved($expense);

        return back()->with('success', __('ui.worker_expenses.created'));
    }

    public function approve(WorkerExpense $workerExpense): RedirectResponse
    {
        Gate::authorize('expenses.approve');

        $workerExpense->status = WorkerExpenseStatus::Approved;
        $workerExpense->approved_by = Auth::id();
        $workerExpense->approved_at = now();
        $workerExpense->rejection_reason = null;
        $workerExpense->save();

        // Fuel expense → auto-create the matching company expense (idempotent).
        app(WorkerFuelExpenseService::class)->syncApproved($workerExpense);

        $this->notifyWorker($workerExpense, true);

        return back()->with('success', __('ui.worker_expenses.approved'));
    }

    /**
     * Send a worker expense to the Super-Admin review queue (Part C) instead of
     * approving/rejecting. Creates the mirror Expense in the in_review state; the
     * Super Admin makes the final call there.
     */
    public function sendToReview(WorkerExpense $workerExpense): RedirectResponse
    {
        Gate::authorize('expenses.approve');

        $workerExpense->status = WorkerExpenseStatus::InReview;
        $workerExpense->approved_by = Auth::id();
        $workerExpense->approved_at = now();
        $workerExpense->rejection_reason = null;
        $workerExpense->save();

        app(WorkerFuelExpenseService::class)->mirrorForReview($workerExpense);

        return back()->with('success', __('ui.worker_expenses.sent_to_review'));
    }

    public function reject(Request $request, WorkerExpense $workerExpense): RedirectResponse
    {
        Gate::authorize('expenses.approve');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:300'],
        ]);

        $workerExpense->status = WorkerExpenseStatus::Rejected;
        $workerExpense->rejection_reason = $validated['reason'];
        $workerExpense->approved_by = Auth::id();
        $workerExpense->approved_at = now();
        $workerExpense->save();

        $this->notifyWorker($workerExpense, false);

        return back()->with('success', __('ui.worker_expenses.rejected'));
    }

    /** Tell the worker their expense was approved / rejected (PWA bell). */
    private function notifyWorker(WorkerExpense $expense, bool $approved): void
    {
        app(NotificationDispatcher::class)->dispatchToUser(
            NotificationType::ExpenseDecided,
            $expense->employee?->user,
            [
                'title_es' => $approved ? 'Tu gasto fue aprobado' : 'Tu gasto fue rechazado',
                'title_en' => $approved ? 'Your expense was approved' : 'Your expense was rejected',
                'entity' => $expense->description, 'url' => '/worker',
            ],
        );
    }

    public function downloadReceipt(WorkerExpense $workerExpense): BinaryFileResponse
    {
        Gate::authorize('expenses.view');

        abort_unless($workerExpense->receipt_path && Storage::disk('local')->exists($workerExpense->receipt_path), 404);

        app(AuditLogger::class)->log('viewed', $workerExpense, ['context' => 'receipt_download']);

        return response()->file(Storage::disk('local')->path($workerExpense->receipt_path));
    }
}
