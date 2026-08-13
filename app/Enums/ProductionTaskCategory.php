<?php

namespace App\Enums;

/**
 * The trade a production task belongs to (2026-08-13). Advisory grouping for
 * the project task board and per-category rollups.
 */
enum ProductionTaskCategory: string
{
    case Civil = 'civil';
    case Electrical = 'electrical';
    case Plumbing = 'plumbing';
    case Finishing = 'finishing';
    case Other = 'other';
}
