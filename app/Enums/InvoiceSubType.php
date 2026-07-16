<?php

namespace App\Enums;

/**
 * Pre-invoice (proforma) vs the final invoice.
 */
enum InvoiceSubType: string
{
    case Pre = 'pre';
    case Final = 'final';
}
