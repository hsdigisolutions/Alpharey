<?php

namespace App\Enums;

/**
 * How overtime is paid (Settings → Overtime policies).
 */
enum OvertimePolicyType: string
{
    case Percentage = 'percentage';       // OT paid at rate × (1 + percentage/100)
    case FixedHourly = 'fixed_hourly';    // OT paid at a fixed hourly rate
    case AccumulateDays = 'accumulate_days'; // OT accrues as time off
    case None = 'none';
}
