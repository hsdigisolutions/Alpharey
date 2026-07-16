<?php

namespace App\Enums;

/**
 * Money status of an invoice, derived server-side from the payment records
 * (never trusted from the client).
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Pending = 'pending';
}
