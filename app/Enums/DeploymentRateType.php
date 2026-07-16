<?php

namespace App\Enums;

enum DeploymentRateType: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case PerMeter = 'per_meter';
}
