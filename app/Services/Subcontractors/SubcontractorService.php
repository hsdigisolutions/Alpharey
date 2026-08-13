<?php

namespace App\Services\Subcontractors;

use App\Enums\ExpenseResponsibility;
use App\Enums\ExpenseType;
use App\Enums\PaymentStatus;
use App\Enums\SubcontractorPaymentStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Scopes\CompanyScope;
use App\Models\Subcontractor;
use App\Models\SubcontractorPayment;
use App\Models\SubcontractorWorker;
use Illuminate\Support\Facades\DB;

/**
 * Subcontractor (thaekedar) lifecycle. Worker totals are computed (days × rate),
 * never trusted from the client. Marking a payment paid posts an Expense to the
 * subcontractor's OWN company + project (never the session's active company —
 * whoever is browsing) and links it, so the money shows up in Gastos; reverting
 * removes it. Keyed off `expense_id` so re-marking cannot double-charge.
 */
class SubcontractorService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function addWorker(Subcontractor $subcontractor, array $data): SubcontractorWorker
    {
        $worker = new SubcontractorWorker($data);
        $worker->subcontractor_id = $subcontractor->id;
        $worker->total_agreed = (string) $this->workerTotal($data);
        $worker->save();

        return $worker;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateWorker(SubcontractorWorker $worker, array $data): SubcontractorWorker
    {
        $worker->fill($data);
        $worker->total_agreed = (string) $this->workerTotal(array_merge($worker->only(['days_worked', 'agreed_rate']), $data));
        $worker->save();

        return $worker;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addPayment(Subcontractor $subcontractor, array $data): SubcontractorPayment
    {
        $next = (int) SubcontractorPayment::query()
            ->where('subcontractor_id', $subcontractor->id)
            ->max('payment_number') + 1;

        $payment = new SubcontractorPayment($data);
        $payment->subcontractor_id = $subcontractor->id;
        $payment->payment_number = $next;
        $payment->status = SubcontractorPaymentStatus::Pending;
        $payment->save();

        return $payment;
    }

    /**
     * Mark a payment paid and post the matching Expense. Idempotent: a payment
     * that already carries an expense is left as-is.
     */
    public function markPaid(SubcontractorPayment $payment): SubcontractorPayment
    {
        if ($payment->expense_id !== null) {
            return $payment;
        }

        return DB::transaction(function () use ($payment): SubcontractorPayment {
            $subcontractor = $payment->subcontractor;

            $expense = new Expense;
            $expense->fill([
                'type' => ExpenseType::Other->value,
                'project_id' => $subcontractor->project_id,
                'date' => $payment->payment_date?->toDateString() ?? now()->toDateString(),
                'subtotal' => (string) $payment->amount,
                'vat_rate' => null,
                'vat_amount' => '0',
                'total' => (string) $payment->amount,
                'payment_status' => PaymentStatus::Paid->value,
                'notes' => "Subcontrata {$subcontractor->name}: Pago {$payment->payment_number}",
            ]);
            // The subcontractor's OWN company, never the browsing session's.
            $expense->company_id = $subcontractor->company_id;
            $expense->save();

            $payment->expense_id = $expense->id;
            $payment->status = SubcontractorPaymentStatus::Paid;
            $payment->save();

            return $payment;
        });
    }

    /**
     * Revert a paid payment: drop its Expense and set it back to pending.
     */
    public function markPending(SubcontractorPayment $payment): SubcontractorPayment
    {
        return DB::transaction(function () use ($payment): SubcontractorPayment {
            if ($payment->expense_id !== null) {
                Expense::query()->withoutGlobalScope(CompanyScope::class)
                    ->whereKey($payment->expense_id)->delete();
                $payment->expense_id = null;
            }
            $payment->status = SubcontractorPaymentStatus::Pending;
            $payment->save();

            return $payment;
        });
    }

    /**
     * Delete a payment, removing its Expense too.
     */
    public function deletePayment(SubcontractorPayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            if ($payment->expense_id !== null) {
                Expense::query()->withoutGlobalScope(CompanyScope::class)
                    ->whereKey($payment->expense_id)->delete();
            }
            $payment->delete();
        });
    }

    /**
     * The LIVE settlement (confirmed model 2026-08-13). Everything is derived
     * fresh on every call — nothing here is stored:
     *
     *   thaekedar_profit = budget − our employees (live from attendance)
     *                             − external workers (manual rows)
     *                             − approved project expenses  [Scenario A only]
     *   still_to_pay     = thaekedar_profit − payments already paid
     *   our_profit       = client − budget            [Scenario A]
     *                    = client − budget − expenses [Scenario B — ours]
     *
     * Our employees' cost is the sum of their frozen attendance totals on the
     * subcontractor's project, date-bounded by start/end when set (confirmed
     * decision 2) — the admin never types these figures.
     *
     * @return array<string, mixed>
     */
    public function settlement(Subcontractor $subcontractor): array
    {
        $budget = $subcontractor->agreed_budget !== null ? (float) $subcontractor->agreed_budget : null;
        $client = $subcontractor->client_amount !== null ? (float) $subcontractor->client_amount : null;
        $thaekedarBears = $subcontractor->expense_responsibility === ExpenseResponsibility::Thaekedar;

        $ourEmployees = $this->ourEmployeeLines($subcontractor);
        $ourEmployeesTotal = round(array_sum(array_column($ourEmployees, 'total')), 2);

        $externalTotal = round((float) SubcontractorWorker::query()
            ->where('subcontractor_id', $subcontractor->id)
            ->where('is_our_employee', false)
            ->sum('total_agreed'), 2);

        $expensesTotal = $this->projectExpensesTotal($subcontractor);

        $paidSoFar = round((float) SubcontractorPayment::query()
            ->where('subcontractor_id', $subcontractor->id)
            ->where('status', SubcontractorPaymentStatus::Paid)
            ->sum('amount'), 2);

        $deductions = $ourEmployeesTotal + $externalTotal + ($thaekedarBears ? $expensesTotal : 0.0);
        $thaekedarProfit = $budget !== null ? round($budget - $deductions, 2) : null;
        $stillToPay = $thaekedarProfit !== null ? round($thaekedarProfit - $paidSoFar, 2) : null;

        $ourProfit = ($client !== null && $budget !== null)
            ? round($client - $budget - ($thaekedarBears ? 0.0 : $expensesTotal), 2)
            : null;

        return [
            'agreed_budget' => $budget,
            'client_amount' => $client,
            'expense_responsibility' => $subcontractor->expense_responsibility->value,
            'our_employees' => $ourEmployees,
            'our_employees_total' => $ourEmployeesTotal,
            'external_workers_total' => $externalTotal,
            'expenses_total' => $expensesTotal,
            'thaekedar_profit' => $thaekedarProfit,
            'paid_so_far' => $paidSoFar,
            'still_to_pay' => $stillToPay,
            'our_profit' => $ourProfit,
        ];
    }

    /**
     * Per-employee live lines for Tab 1 Section A: days + cost straight from
     * the frozen attendance snapshots on this project — read-only figures.
     *
     * @return list<array<string, mixed>>
     */
    private function ourEmployeeLines(Subcontractor $subcontractor): array
    {
        if ($subcontractor->project_id === null) {
            return [];
        }

        $employeeIds = SubcontractorWorker::query()
            ->where('subcontractor_id', $subcontractor->id)
            ->where('is_our_employee', true)
            ->whereNotNull('employee_id')
            ->pluck('employee_id');

        if ($employeeIds->isEmpty()) {
            return [];
        }

        $rows = Attendance::query()->withoutGlobalScopes()
            ->where('project_id', $subcontractor->project_id)
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['present', 'late', 'early_leave'])
            ->when($subcontractor->start_date !== null, fn ($q) => $q->whereDate('date', '>=', $subcontractor->start_date?->toDateString()))
            ->when($subcontractor->end_date !== null, fn ($q) => $q->whereDate('date', '<=', $subcontractor->end_date?->toDateString()))
            ->selectRaw('employee_id')
            ->selectRaw('COUNT(DISTINCT date) as days')
            ->selectRaw('COALESCE(SUM(total_amount),0) as total')
            ->groupBy('employee_id')
            ->get();

        // Drop ONLY the tenancy scope — never SoftDeletes (a deleted employee
        // must not price a settlement).
        $employees = Employee::query()->withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', $employeeIds)->get(['id', 'full_name', 'designation'])->keyBy('id');

        return $employeeIds->map(function ($id) use ($rows, $employees): array {
            $agg = $rows->firstWhere('employee_id', $id);
            $employee = $employees->get($id);
            $days = (float) ($agg?->getAttribute('days') ?? 0);
            $total = round((float) ($agg?->getAttribute('total') ?? 0), 2);

            return [
                'employee_id' => (int) $id,
                'name' => $employee->full_name ?? '—',
                'designation' => $employee->designation ?? null,
                'days' => $days,
                'rate' => $days > 0 ? round($total / $days, 2) : null,
                'total' => $total,
            ];
        })->values()->all();
    }

    /**
     * Approved project expenses inside the subcontract window. The auto-posted
     * payment Gastos stay out by construction (they are never approved).
     */
    private function projectExpensesTotal(Subcontractor $subcontractor): float
    {
        if ($subcontractor->project_id === null) {
            return 0.0;
        }

        return round((float) Expense::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $subcontractor->company_id)
            ->where('project_id', $subcontractor->project_id)
            ->where('approved', true)
            ->when($subcontractor->start_date !== null, fn ($q) => $q->whereDate('date', '>=', $subcontractor->start_date?->toDateString()))
            ->when($subcontractor->end_date !== null, fn ($q) => $q->whereDate('date', '<=', $subcontractor->end_date?->toDateString()))
            ->sum('total'), 2);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function workerTotal(array $data): float
    {
        $days = (float) ($data['days_worked'] ?? 0);
        $rate = (float) ($data['agreed_rate'] ?? 0);

        return round($days * $rate, 2);
    }
}
