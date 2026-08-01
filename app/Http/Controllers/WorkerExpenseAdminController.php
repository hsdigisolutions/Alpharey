<?php

namespace App\Http\Controllers;

use App\Enums\WorkerExpenseStatus;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Models\WorkerExpense;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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
            'can' => ['approve' => Gate::allows('expenses.approve')],
        ]);
    }

    public function approve(WorkerExpense $workerExpense): RedirectResponse
    {
        Gate::authorize('expenses.approve');

        $workerExpense->status = WorkerExpenseStatus::Approved;
        $workerExpense->approved_by = Auth::id();
        $workerExpense->approved_at = now();
        $workerExpense->rejection_reason = null;
        $workerExpense->save();

        return back()->with('success', __('ui.worker_expenses.approved'));
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

        return back()->with('success', __('ui.worker_expenses.rejected'));
    }

    public function downloadReceipt(WorkerExpense $workerExpense): BinaryFileResponse
    {
        Gate::authorize('expenses.view');

        abort_unless($workerExpense->receipt_path && Storage::disk('local')->exists($workerExpense->receipt_path), 404);

        app(AuditLogger::class)->log('viewed', $workerExpense, ['context' => 'receipt_download']);

        return response()->file(Storage::disk('local')->path($workerExpense->receipt_path));
    }
}
