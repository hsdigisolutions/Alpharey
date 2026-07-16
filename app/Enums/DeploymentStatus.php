<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
