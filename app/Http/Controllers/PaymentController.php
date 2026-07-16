<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Invoices\InvoiceTotals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Payment records against an invoice. The invoice's paid_amount and
 * payment_status are ALWAYS re-derived from these rows by InvoiceTotals —
 * never sent by the client.
 */
class PaymentController extends Controller
{
    public function store(Request $request, Invoice $invoice, InvoiceTotals $totals): RedirectResponse
    {
        Gate::authorize('invoices.edit');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($invoice, $validated, $totals): void {
            $payment = new Payment($validated);
            $payment->invoice_id = $invoice->id;
            // Take the company from the invoice, not the session: the payment
            // belongs to whoever issued the invoice.
            $payment->company_id = $invoice->company_id;
            $payment->save();

            $totals->applyPaymentStatus($invoice);
            $invoice->save();
        });

        return back()->with('success', __('ui.invoices.payment_saved'));
    }

    public function destroy(Payment $payment, InvoiceTotals $totals): RedirectResponse
    {
        Gate::authorize('invoices.edit');

        DB::transaction(function () use ($payment, $totals): void {
            $invoice = $payment->invoice;
            $payment->delete();

            if ($invoice !== null) {
                // The status must fall back (Paid -> Partial/Unpaid) when a
                // payment is removed, so re-derive rather than leave it stale.
                $totals->applyPaymentStatus($invoice->refresh());
                $invoice->save();
            }
        });

        return back()->with('success', __('ui.invoices.payment_deleted'));
    }
}
