<?php

namespace App\Enums;

/**
 * Who ultimately bears an expense (the legacy "Expense Bearable By"):
 *
 * - Company    — a company cost; not billed to a client, not touching payroll.
 * - Client     — passed on to the client; the ONLY kind that feeds project
 *                invoicing / auto-calc.
 * - Employee   — the worker bears it: reimbursed if they fronted it, or deducted
 *                from salary if flagged (company-card spend they must repay).
 * - Unbillable — excluded from everything (a write-off).
 */
enum BearableBy: string
{
    case Company = 'company';
    case Client = 'client';
    case Employee = 'employee';
    case Unbillable = 'unbillable';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }
}
