<?php

namespace App\Support;

use App\Models\Employee;

/**
 * The worker geolocation + selfie privacy notice — the single authority for
 * "has this worker been informed, of the CURRENT notice, before punching?".
 *
 * The notice is an INFORMATION duty, not a consent gate: Spanish law bases
 * worker monitoring on the employment relationship and the employer's legal
 * obligations (LOPDGDD art. 90 geolocation; RD-ley 8/2019 time record), so
 * what is recorded is that the worker was shown and acknowledged the notice —
 * see docs/GDPR_WORKER_NOTICE.md for the reasoning and the notice text.
 *
 * Bump VERSION whenever the notice text changes materially (new data captured,
 * changed retention, new recipient). Every worker whose acknowledged version
 * is lower is re-shown the notice before their next punch — the acknowledgement
 * has to match the notice they actually saw.
 */
final class WorkerPrivacyNotice
{
    /**
     * The current notice version. Start at 1; increment on any material change
     * to what the notice tells the worker. Kept as a constant (not a setting)
     * so a change is a reviewed code+text change, versioned in git alongside
     * the lang keys that render it.
     *
     * (Untyped const deliberately — local dev runs PHP 8.2, and typed class
     * constants are 8.3-only; see CLAUDE.md scaffolding decision 1.)
     */
    public const VERSION = 1;

    public static function acknowledged(Employee $employee): bool
    {
        return $employee->privacy_notice_ack_at !== null
            && (int) $employee->privacy_notice_ack_version >= self::VERSION;
    }
}
