<?php

namespace App\Enums;

/**
 * How a day's hours are captured (Screen 11 mode toggle):
 *  - Hourly: check-in/out times → hours auto-calculated
 *  - ProjectBased: hours entered manually against a project
 */
enum AttendanceMode: string
{
    case Hourly = 'hourly';
    case ProjectBased = 'project_based';
}
