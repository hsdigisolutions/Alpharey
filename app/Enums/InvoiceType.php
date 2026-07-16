<?php

namespace App\Enums;

/**
 * Screen 10 — the two tabs on one screen. Sale = money in (to a client),
 * Expense = money out (from a vendor).
 */
enum InvoiceType: string
{
    case Sale = 'sale';
    case Expense = 'expense';
}
