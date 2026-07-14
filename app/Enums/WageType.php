<?php

namespace App\Enums;

enum WageType: string
{
    case Daily = 'daily';
    case Hourly = 'hourly';
    case Monthly = 'monthly';
    case PerMeter = 'per_meter';
}
