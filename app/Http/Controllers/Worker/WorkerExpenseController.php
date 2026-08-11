<?php

namespace App\Http\Controllers\Worker;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Models\WorkerExpense;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Feature 2 — Worker-submitted expense at (or after) check-out.
 *
 * Workers may submit multiple expenses. Each one starts as `pending` and waits
 * for an admin to approve or reject via WorkerExpenseAdminController.
 *
 * company_id comes from the employee's company, never from request input.
 */
class WorkerExpenseController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'category' => ['required', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'], // 5 MB
        ]);

        $expense = new WorkerExpense([
            'employee_id' => $employee->id,
            'date' => $validated['date'],
            'amount' => $validated['amount'],
            'category' => $validated['category'],
            'description' => $validated['description'],
        ]);
        $expense->company_id = $employee->company_id;

        if ($request->hasFile('receipt')) {
            $path = $request->file('receipt')->store(
                "worker-expense-receipts/{$employee->company_id}/{$employee->id}",
                'local',
            );
            $expense->receipt_path = $path === false ? null : $path;
        }

        $expense->save();

        // Ping the admins/managers that a receipt is waiting for review.
        app(NotificationDispatcher::class)->dispatch(
            NotificationType::ExpensePending,
            (int) $employee->company_id,
            [
                'title_es' => "Nuevo gasto de {$employee->full_name}",
                'title_en' => "New expense from {$employee->full_name}",
                'entity' => $employee->full_name, 'url' => '/worker-expenses',
            ],
        );

        return back()->with('success', __('ui.worker.expense_submitted'));
    }

    private function resolveEmployee(Request $request): Employee
    {
        return Employee::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('user_id', $request->user()?->id)
            ->firstOrFail();
    }
}
