<?php

namespace App\Enums;

/**
 * How a project-designation rate is measured. Drives both the worker cost and
 * the client revenue for that designation on that project.
 */
enum ProjectRateType: string
{
    case PerHour = 'per_hour';
    case PerDay = 'per_day';
    case PerMeter = 'per_meter';
}
