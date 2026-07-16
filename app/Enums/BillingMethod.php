<?php

namespace App\Enums;

/**
 * Cross-company deployment billing method (REQUIREMENTS.md §3).
 *
 * ONLY OptionA is automated — employee stays on the home-company payroll,
 * the host company is charged an internal cross-charge (DECISIONS.md +
 * PAYROLL_DEPLOYMENTS.md). OptionB (host processes the payroll) is NEVER
 * implemented — it is cesión ilegal de trabajadores under Spanish labour
 * law (dev skill Rule 13). OptionC (split) keeps the door open via the
 * split_pct field but is not automated in this phase.
 */
enum BillingMethod: string
{
    case OptionA = 'option_a'; // home company pays, host charged internally
    case OptionB = 'option_b'; // host processes payroll — NOT automated (illegal)
    case OptionC = 'option_c'; // split cost by percentage — not automated yet

    public function isAutomated(): bool
    {
        return $this === self::OptionA;
    }
}
