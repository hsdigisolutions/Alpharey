<?php

namespace App\Enums;

/**
 * The condition kit is in when a worker hands it back. Good goes back into the
 * store as usual; Damaged / Lost write the unit off (total −q) and record an
 * incident against the responsible worker (no auto-cost — the admin decides).
 */
enum EquipmentReturnCondition: string
{
    case Good = 'good';
    case Damaged = 'damaged';
    case Lost = 'lost';

    /** Damaged or lost — a write-off that records an incident. */
    public function isIncident(): bool
    {
        return $this !== self::Good;
    }
}
