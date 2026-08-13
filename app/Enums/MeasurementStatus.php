<?php

namespace App\Enums;

/**
 * The review state of a measurement (2026-08-13). A worker/clerk logs it
 * pending; a supervisor approves it (it then feeds per-meter billing/P&L) or
 * rejects it with a reason so the worker knows what to fix and resubmit.
 *
 * `approved` (the legacy boolean) is kept in sync = (status === Approved) so
 * existing readers keep working; `status` is the authority.
 */
enum MeasurementStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
