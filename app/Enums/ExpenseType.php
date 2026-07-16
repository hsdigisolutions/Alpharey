<?php

namespace App\Enums;

/**
 * Screen 10 Gastos tab — the Spanish document types a supplier cost arrives
 * as. Albarán = delivery note, factura = invoice, ticket = till receipt.
 */
enum ExpenseType: string
{
    case Albaran = 'albaran';
    case Factura = 'factura';
    case Ticket = 'ticket';
    case Other = 'other';
}
