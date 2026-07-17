<?php

namespace App\Enums;

/**
 * Screen 22 — leave request lifecycle.
 */
enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
