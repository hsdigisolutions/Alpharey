<?php

namespace App\Enums;

/**
 * How a voluntary weekend day is priced, on top of the day's base pay.
 *
 *   Normal  the usual rate (no premium)
 *   X15     rate × 1.5
 *   X2      rate × 2
 *   Custom  a flat amount entered by hand (weekend_rate_amount)
 */
enum WeekendRateType: string
{
    case Normal = 'normal';
    case X15 = 'x1.5';
    case X2 = 'x2';
    case Custom = 'custom';

    /** The multiplier to apply to the base day total, or null for custom. */
    public function multiplier(): ?float
    {
        return match ($this) {
            self::Normal => 1.0,
            self::X15 => 1.5,
            self::X2 => 2.0,
            self::Custom => null,
        };
    }
}
