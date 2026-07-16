<?php

namespace App\Enums;

/**
 * Salary-advance lifecycle (Screen 12, advances section). `deducted` is the
 * terminal state: the advance has been taken off a payroll month and can no
 * longer be edited.
 */
enum AdvanceStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Deducted = 'deducted';
}
