<?php

namespace App\Enums;

/**
 * Item 8 (2026-09-13) — how a worker-referral commission accrues to the REFERRER
 * from the REFERRED worker's activity. Set per referral (amount + window separate).
 *
 *   PerDay    referred worker's worked days that month × amount
 *   PerHour   referred worker's net hours that month × amount
 *   PerMonth  a flat amount for any month with ≥1 worked day (within the window)
 *   OneTime   a single payout, in the first month the referred worker records
 *             real worked attendance (never merely on hire → no-show safe)
 */
enum ReferralRateType: string
{
    case PerDay = 'per_day';
    case PerHour = 'per_hour';
    case PerMonth = 'per_month';
    case OneTime = 'one_time';
}
