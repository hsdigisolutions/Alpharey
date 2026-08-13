<?php

namespace App\Enums;

/**
 * The lifecycle of a production task (2026-08-13). Separate from the completion
 * traffic light (derived from completed/planned) — a task can be `in_progress`
 * at 10% or 90%.
 */
enum ProductionTaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
}
