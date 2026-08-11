<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The single rule for "a day with NO attendance row should count as an absence".
 *
 * A PAST weekday (before today) the worker was already employed for — on/after
 * their joining date, and not a Saturday/Sunday — with no record at all is an
 * absence, shown live so every calendar (worker PWA, admin grid, employee tab)
 * agrees immediately, without waiting for the nightly attendance:auto-absent
 * sweep that later writes the real row. Weekends, today (still in progress),
 * future days, and days before the worker joined are never absences.
 *
 * The caller must have already established there is NO row for the day.
 */
class AttendanceAbsence
{
    public static function isUnrecordedAbsence(Carbon $date, Carbon $today, ?Carbon $joiningDate): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        // Today (not over yet) and any future date are never absences.
        if ($date->gte($today)) {
            return false;
        }

        // A day before the worker joined is not their absence.
        if ($joiningDate !== null && $date->lt($joiningDate->copy()->startOfDay())) {
            return false;
        }

        return true;
    }
}
