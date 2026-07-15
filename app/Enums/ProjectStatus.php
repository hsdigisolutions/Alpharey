<?php

namespace App\Enums;

/**
 * The 5 kanban columns of Screen 08.
 */
enum ProjectStatus: string
{
    case Active = 'active';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case OnHold = 'on_hold';
}
