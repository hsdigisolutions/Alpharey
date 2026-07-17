<?php

namespace App\Enums;

/**
 * Screen 23 — the stock ledger's movement types. The type carries the
 * direction; quantities are always stored positive.
 */
enum StockMovementType: string
{
    case StockIn = 'stock_in';
    case Issue = 'issue';
    case Return = 'return';
    case Adjustment = 'adjustment';
    case Damaged = 'damaged';

    /**
     * How this movement moves available stock. Adjustment is the exception:
     * it SETS the balance rather than shifting it, so it has no sign.
     */
    public function sign(): int
    {
        return match ($this) {
            self::StockIn, self::Return => 1,
            self::Issue, self::Damaged => -1,
            self::Adjustment => 0,
        };
    }
}
