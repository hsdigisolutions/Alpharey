<?php

namespace App\Enums;

/**
 * How a day's pay is calculated (Screen 11 "Tipo de jornada"). Distinct from
 * AttendanceMode (how hours are captured): a dehadi (daily) worker is paid by
 * the day, not the hour.
 *
 *   Full     total = daily rate × 1.0
 *   Half     total = daily rate × 0.5
 *   Hourly   total = hours worked × hourly rate
 *   PerMeter total = quantity × per-meter rate
 */
enum DayType: string
{
    case Full = 'full';
    case Half = 'half';
    case Hourly = 'hourly';
    case PerMeter = 'per_meter';
}
