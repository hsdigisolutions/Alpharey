<?php

namespace App\Enums;

/**
 * Attendance day status (Screen 11 cell colors).
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case EarlyLeave = 'early_leave';
    case Leave = 'leave';
}
