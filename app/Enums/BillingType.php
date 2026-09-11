<?php

namespace App\Enums;

/**
 * How a project is billed (Screen 09 info bar).
 */
enum BillingType: string
{
    case Fixed = 'fixed';
    case Hourly = 'hourly';
    case PerMeter = 'per_meter';
    case Milestone = 'milestone';
    // Revenue = Σ (Production-Task "Log Work" quantity × that task's client_rate).
    // Replaces per_meter as the offered task/unit-based billing (per_meter is kept
    // for any legacy data but no longer shown in the project form).
    case TaskBased = 'task_based';
}
