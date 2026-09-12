<?php

namespace App\Http\Controllers;

use App\Enums\NotificationType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use App\Services\Invoices\InvoiceTotals;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            // Item 6 — proof-of-payment receipt (mainly a bank transfer). Documentation
            // only: it never changes the invoice's paid/unpaid derivation.
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $wasPaid = $invoice->payment_status === PaymentStatus::Paid;

        DB::transaction(function () use ($request, $invoice, $validated, $totals): void {
            $payment = new Payment($validated);
            $payment->invoice_id = $invoice->id;
            // Take the company from the invoice, not the session: the payment
            // belongs to whoever issued the invoice.
            $payment->company_id = $invoice->company_id;
            $this->storeReceipt($request, $payment, $invoice->company_id);
            $payment->save();

            $totals->applyPaymentStatus($invoice);
            $invoice->save();
        });

        // Fully-settled just now (not already paid) → Super-Admin-visible event.
        if (! $wasPaid && $invoice->payment_status === PaymentStatus::Paid) {
            app(NotificationDispatcher::class)->dispatch(NotificationType::InvoicePaid, $invoice->company_id, [
                'title_es' => "Factura pagada: {$invoice->number}",
                'title_en' => "Invoice paid: {$invoice->number}",
                'entity' => $invoice->number, 'url' => '/invoices',
            ]);
        }

        return back()->with('success', __('ui.invoices.payment_saved'));
    }

    public function destroy(Payment $payment, InvoiceTotals $totals): RedirectResponse
    {
        Gate::authorize('invoices.edit');

        $receiptPath = $payment->receipt_path;

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

        if ($receiptPath !== null && Storage::disk('local')->exists($receiptPath)) {
            Storage::disk('local')->delete($receiptPath);
        }

        return back()->with('success', __('ui.invoices.payment_deleted'));
    }

    /**
     * Download a payment's proof-of-payment receipt — gated (invoices) + audited,
     * served from the private disk (never a public URL).
     */
    public function downloadReceipt(Payment $payment, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('invoices.view');
        abort_if($payment->receipt_path === null, 404);

        $audit->log('viewed', $payment, null, null, 'Payment receipt', 'invoices');

        return Storage::disk('local')->download($payment->receipt_path, $payment->receipt_name ?? 'recibo');
    }

    /**
     * Store an uploaded proof-of-payment receipt on the private disk (randomized
     * filename, original kept as metadata). receipt_path/name are server-set.
     */
    private function storeReceipt(Request $request, Payment $payment, int $companyId): void
    {
        $file = $request->file('receipt');
        if ($file === null) {
            return;
        }

        $folder = "payment-receipts/{$companyId}";
        $payment->receipt_path = $file->storeAs($folder, Str::random(40).'.'.$file->getClientOriginalExtension(), 'local');
        $payment->receipt_name = $file->getClientOriginalName();
    }
}
