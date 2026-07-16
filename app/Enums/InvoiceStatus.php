<?php

namespace App\Enums;

/**
 * Document lifecycle of an invoice — distinct from PaymentStatus, which
 * tracks the money (spec Screen 10 lists both columns separately).
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Paid = 'paid';
}
