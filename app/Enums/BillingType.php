<?php

namespace App\Enums;

/**
 * How a project is billed (Screen 09 info bar).
 */
enum BillingType: string
{
    case Fixed = 'fixed';
    case Hourly = 'hourly';
    case PerMeter = 'per_meter';
    case Milestone = 'milestone';
}
