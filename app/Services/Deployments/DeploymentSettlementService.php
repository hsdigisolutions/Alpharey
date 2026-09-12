<?php

namespace App\Services\Deployments;

use App\Enums\PaymentStatus;
use App\Models\DeploymentCharge;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * The SINGLE writer of a deployment settlement (2026-09-12). Marking a completed
 * deployment paid/unpaid touches THREE linked records that must always agree:
 *
 *   host Expense.approved   ←→   home Invoice payment_status   ←→   DeploymentCharge.settlement_status
 *
 * Both entry points route through here so the state can never diverge:
 *   - the HOST approving the internal_deployment Expense (Expenses tab), and
 *   - the "Mark as paid" control on the Deployments screen.
 *
 * Fully reversible (un-approve → Unpaid). It is P&L-SAFE: approving the
 * internal_deployment expense adds NOTHING to project P&L, because
 * ProfitabilityService excludes that type by construction (Step 1) — the
 * deployed labour is already counted once via host-company attendance.
 */
class DeploymentSettlementService
{
    /** Reference tag identifying the auto-created settlement payment. */
    private const PAYMENT_REF = 'deployment_settlement';

    /**
     * Apply a paid/unpaid decision to the whole settlement. Idempotent and
     * cross-company (drops CompanyScope) — the acting admin may be the host,
     * settling money that lives partly on the home company's records.
     */
    public function settle(DeploymentCharge $charge, bool $paid, ?int $deciderId): void
    {
        DB::transaction(function () use ($charge, $paid, $deciderId): void {
            $this->syncExpense($charge, $paid, $deciderId);
            $this->syncInvoice($charge, $paid, $deciderId);

            // The charge's own settlement columns (server-set, not fillable).
            $charge->settlement_status = $paid ? 'paid' : 'unpaid';
            $charge->paid_at = $paid ? now() : null;
            $charge->paid_by = $paid ? $deciderId : null;
            $charge->save();
        });
    }

    /**
     * Keep the host's internal_deployment expense's `approved` flag in lockstep
     * with the settlement, so approving from EITHER screen looks the same. This
     * is P&L-safe (the type is excluded from project P&L) and payroll-safe (the
     * expense has no employee_id / is_reimbursable, so payroll never counts it).
     */
    private function syncExpense(DeploymentCharge $charge, bool $paid, ?int $deciderId): void
    {
        if ($charge->expense_id === null) {
            return;
        }

        $expense = Expense::query()->withoutGlobalScope(CompanyScope::class)->find($charge->expense_id);
        if ($expense === null) {
            return;
        }

        $expense->approved = $paid;
        $expense->approved_by = $paid ? $deciderId : null;
        $expense->approved_at = $paid ? now() : null;
        $expense->review_status = null;
        $expense->save();
    }

    /**
     * Mark the home invoice paid/unpaid by adding or removing a single
     * settlement Payment, then re-deriving payment_status (the invoice's status
     * is ALWAYS derived from payments — never set directly). This also gives the
     * "when paid" history naturally (the payment row + its date).
     */
    private function syncInvoice(DeploymentCharge $charge, bool $paid, ?int $deciderId): void
    {
        $invoice = Invoice::query()->withoutGlobalScope(CompanyScope::class)
            ->where('deployment_charge_id', $charge->id)->first();
        if ($invoice === null) {
            return; // no invoice yet (pre-backfill) — expense + charge still sync
        }

        // Remove any prior settlement payment first (idempotent + reversible).
        Payment::query()->withoutGlobalScope(CompanyScope::class)
            ->where('invoice_id', $invoice->id)
            ->where('reference', self::PAYMENT_REF)
            ->delete();

        if ($paid) {
            $payment = new Payment([
                'invoice_id' => $invoice->id,
                'amount' => (string) $invoice->total,
                'payment_date' => now()->toDateString(),
                'reference' => self::PAYMENT_REF,
                'notes' => __('ui.deployments.invoice_paid_note'),
            ]);
            // company_id is not mass assignable — set to the invoice's company
            // (the HOME company), never the acting (host) session.
            $payment->company_id = $invoice->company_id;
            $payment->save();
        }

        // Re-derive payment_status from the payments UNSCOPED: the payment lives
        // on the HOME company, but the acting admin may be the HOST — a scoped
        // sum would miss it and read Unpaid. (This mirrors InvoiceTotals'
        // applyPaymentStatus, but the tenancy scope must be dropped here.)
        $total = (float) $invoice->total;
        $sum = (float) Payment::query()->withoutGlobalScope(CompanyScope::class)
            ->where('invoice_id', $invoice->id)->sum('amount');

        $invoice->paid_amount = (string) round($sum, 2);
        $invoice->payment_status = match (true) {
            $sum <= 0 => PaymentStatus::Unpaid,
            $sum + 0.001 >= $total => PaymentStatus::Paid,
            default => PaymentStatus::Partial,
        };
        $invoice->payment_date = $sum > 0 ? now() : null;
        $invoice->save();
    }
}
