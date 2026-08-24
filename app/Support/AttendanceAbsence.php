<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The single authority for a COMPUTED absence — a past weekday the worker was
 * employed for but has no attendance row on yet. Every calendar surface (the
 * worker PWA, the admin grid, the employee-detail Asistencia tab) calls this so
 * they agree by construction, without waiting for the nightly auto-absent sweep.
 *
 * A day is a computed absence only when ALL hold:
 *  - the employee is currently active (an inactive worker is not with us, so a
 *    gap is not an absence);
 *  - it is a weekday (Mon–Fri);
 *  - it is strictly in the past (today and future are never absences);
 *  - it is on/after the later of the joining date, the (re)activation date, and
 *    the transfer date (a worker transferred INTO this company on 24 Aug cannot
 *    be absent here before then — even if their original hire date is older or
 *    null).
 */
class AttendanceAbsence
{
    /**
     * @param  list<int>|null  $workingDays  ISO weekday numbers (1=Mon…7=Sun) that
     *                                       count as working days. Null falls back
     *                                       to Mon–Fri (Sat/Sun off).
     */
    public static function isUnrecordedAbsence(
        Carbon $date,
        Carbon $today,
        ?Carbon $joiningDate,
        bool $active = true,
        ?Carbon $activeSince = null,
        ?Carbon $transferredAt = null,
        ?array $workingDays = null,
    ): bool {
        // An inactive employee ("not working with us now") accrues no absences.
        if (! $active) {
            return false;
        }

        // A non-working day (weekend by default, or per the company's configured
        // working days) is never an absence.
        $isOffDay = $workingDays !== null
            ? ! in_array($date->dayOfWeekIso, $workingDays, true)
            : $date->isWeekend();
        if ($isOffDay) {
            return false;
        }

        if ($date->gte($today)) {
            return false;
        }

        // Count from the LATEST of joining, reactivation and transfer: a worker
        // who was deactivated and later brought back, OR transferred in from
        // another company, starts a fresh count from that later day — an earlier
        // (or null) hire date never back-fills absences at this company.
        $start = $joiningDate;
        if ($activeSince !== null && ($start === null || $activeSince->gt($start))) {
            $start = $activeSince;
        }
        if ($transferredAt !== null && ($start === null || $transferredAt->gt($start))) {
            $start = $transferredAt;
        }

        if ($start !== null && $date->lt($start->copy()->startOfDay())) {
            return false;
        }

        return true;
    }
}
