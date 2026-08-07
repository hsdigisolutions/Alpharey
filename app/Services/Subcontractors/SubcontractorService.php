<?php

namespace App\Services\Subcontractors;

use App\Enums\ExpenseType;
use App\Enums\PaymentStatus;
use App\Enums\SubcontractorPaymentStatus;
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
     * @param  array<string, mixed>  $data
     */
    private function workerTotal(array $data): float
    {
        $days = (float) ($data['days_worked'] ?? 0);
        $rate = (float) ($data['agreed_rate'] ?? 0);

        return round($days * $rate, 2);
    }
}
