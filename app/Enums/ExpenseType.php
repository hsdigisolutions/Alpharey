<?php

namespace App\Enums;

/**
 * Screen 10 Gastos tab — the Spanish document types a supplier cost arrives
 * as. Albarán = delivery note, factura = invoice, ticket = till receipt.
 *
 * `InternalDeployment` is the exception: it is never chosen by a user. The
 * cross-company charge engine posts it on the HOST company when a deployment
 * completes (PAYROLL_DEPLOYMENTS.md §"How it works mechanically", step 4).
 * It carries no vendor, so it stays out of vendor expense reports while still
 * counting toward project cost.
 */
enum ExpenseType: string
{
    case Albaran = 'albaran';
    case Factura = 'factura';
    case Ticket = 'ticket';
    case Other = 'other';
    case InternalDeployment = 'internal_deployment';

    /**
     * The types a human may pick on the Gastos form. Excludes the
     * system-generated deployment charge — a clerk must not be able to hand-
     * create one, or the cross-company report would double-count.
     *
     * @return list<self>
     */
    public static function userSelectable(): array
    {
        // The declared list<self> return type is the guard here: if the enum is
        // ever reordered so this filter leaves a gap, static analysis fails.
        return array_filter(
            self::cases(),
            fn (self $type): bool => $type !== self::InternalDeployment,
        );
    }
}
