<?php

namespace App\Enums;

/** Lifecycle of a subcontractor (thaekedar) engagement. */
enum SubcontractorStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
