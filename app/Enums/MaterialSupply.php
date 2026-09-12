<?php

namespace App\Enums;

/**
 * How materials are supplied/paid on a project (Item 3, 2026-09-12). A
 * descriptive project-level LABEL only — it does not touch any calculation
 * (P&L, invoicing, payroll all keep running off each expense's `bearable_by`).
 * Null = not specified.
 *
 * - ClientIncluded — the client supplies materials and their rate already
 *   covers the material cost (nothing billed separately).
 * - ClientSeparate — the client supplies materials, billed separately (those
 *   material expenses are bearable_by = client).
 * - Company        — we supply and pay for materials (bearable_by = company).
 */
enum MaterialSupply: string
{
    case ClientIncluded = 'client_included';
    case ClientSeparate = 'client_separate';
    case Company = 'company';
}
