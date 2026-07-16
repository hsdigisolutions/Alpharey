<?php

namespace App\Services\Invoices;

use App\Enums\DiscountType;
use App\Enums\PaymentStatus;
use App\Enums\VatRate;
use App\Models\Invoice;

/**
 * The single authority for invoice arithmetic.
 *
 * Totals are ALWAYS derived here from the line items — a client-sent total is
 * never trusted (same rule as Proposals, REQUIREMENTS.md §10).
 *
 * Spanish invoice order of operations:
 *
 *   subtotal   = Σ line totals
 *   − discount (percent of subtotal, or a fixed amount)
 *   = base imponible
 *   + IVA         (base × rate)          ← blank rate means NO VAT line at all
 *   − retención   (base × retention %)   ← IRPF withheld from the base, not the
 *                                          VAT: the client pays it to Hacienda
 *   = total
 */
class InvoiceTotals
{
    /**
     * Recompute every derived figure on an invoice from its line items and
     * payments, then persist. Safe to call repeatedly.
     *
     * @param  list<array{description: string, quantity: float|string, unit_price: float|string}>|null  $lines
     *                                                                                                          when null the invoice's stored line items are used
     */
    public function apply(Invoice $invoice, ?array $lines = null): Invoice
    {
        $subtotal = $lines !== null
            ? $this->subtotalOf($lines)
            : round((float) $invoice->lineItems()->sum('line_total'), 2);

        $discount = $this->discountAmount(
            $subtotal,
            $invoice->discount_type,
            (float) $invoice->discount_value,
        );

        $base = round($subtotal - $discount, 2);

        // A null rate is "No aplica" — no VAT line, not 0% (DECISIONS.md).
        $vat = $invoice->vat_rate instanceof VatRate
            ? $invoice->vat_rate->amountFor($base)
            : 0.0;

        $retention = round($base * ((float) ($invoice->retention_percent ?? 0)) / 100, 2);

        $invoice->subtotal = (string) $subtotal;
        $invoice->discount_amount = (string) $discount;
        $invoice->vat_amount = (string) $vat;
        $invoice->retention_amount = (string) $retention;
        $invoice->total = (string) round($base + $vat - $retention, 2);

        $this->applyPaymentStatus($invoice);

        $invoice->save();

        return $invoice;
    }

    /**
     * Re-derive paid_amount + payment_status from the payment records. The
     * client never sets payment_status directly.
     */
    public function applyPaymentStatus(Invoice $invoice): void
    {
        $paid = $invoice->exists
            ? round((float) $invoice->payments()->sum('amount'), 2)
            : 0.0;

        $total = (float) $invoice->total;

        $invoice->paid_amount = (string) $paid;
        $invoice->payment_status = match (true) {
            $paid <= 0 => PaymentStatus::Unpaid,
            // Guard the float edge: 0.01 short must not read as fully paid.
            $paid + 0.001 >= $total => PaymentStatus::Paid,
            default => PaymentStatus::Partial,
        };
    }

    /**
     * @param  list<array{description: string, quantity: float|string, unit_price: float|string}>  $lines
     */
    public function subtotalOf(array $lines): float
    {
        $sum = 0.0;

        foreach ($lines as $line) {
            $sum += $this->lineTotal($line);
        }

        return round($sum, 2);
    }

    /**
     * @param  array{quantity: float|string, unit_price: float|string}  $line
     */
    public function lineTotal(array $line): float
    {
        return round((float) $line['quantity'] * (float) $line['unit_price'], 2);
    }

    private function discountAmount(float $subtotal, ?DiscountType $type, float $value): float
    {
        if ($value <= 0 || $type === null) {
            return 0.0;
        }

        $amount = $type === DiscountType::Percent
            ? $subtotal * $value / 100
            : $value;

        // Never discount below zero.
        return round(min($amount, $subtotal), 2);
    }
}
