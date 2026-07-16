<?php

namespace App\Enums;

/**
 * Screen 12 — payroll row status. Pending (amber) → Paid (green).
 */
enum PayrollStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
