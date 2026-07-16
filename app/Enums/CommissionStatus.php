<?php

namespace App\Enums;

/**
 * Screen 19 — commission entry lifecycle. `finalized` locks the entry
 * (confirmation required before finalizing); `paid` is terminal.
 */
enum CommissionStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Paid = 'paid';
}
