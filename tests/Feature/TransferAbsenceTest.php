<?php

use App\Support\AttendanceAbsence;
use Illuminate\Support\Carbon;

/**
 * Production bug: a worker transferred INTO a company today was shown ABSENT for
 * days before the transfer (their joining_date was null, so absence-counting had
 * no floor). The absence authority must floor at the transfer date too.
 */
it('never counts an absence before the transfer date, even with a null joining date', function (): void {
    $today = Carbon::parse('2026-08-24');
    $transferredAt = Carbon::parse('2026-08-24 11:49:30');

    // 2026-08-14 is a Friday (a past weekday) BEFORE the transfer. With no
    // joining_date and no active_since, the ONLY floor is the transfer date, so
    // this must NOT be an absence at the new company.
    $beforeTransfer = Carbon::parse('2026-08-14');

    expect(AttendanceAbsence::isUnrecordedAbsence($beforeTransfer, $today, null, true, null, $transferredAt))
        ->toBeFalse();
});

it('would have been a false absence before the fix (null floor) — proves the floor matters', function (): void {
    $today = Carbon::parse('2026-08-24');
    $beforeTransfer = Carbon::parse('2026-08-14'); // Friday

    // No joining, no active_since, NO transfer floor → the old behaviour counted
    // every past weekday as an absence.
    expect(AttendanceAbsence::isUnrecordedAbsence($beforeTransfer, $today, null, true, null, null))
        ->toBeTrue();
});

it('still counts a past weekday AFTER the transfer date as an absence', function (): void {
    $today = Carbon::parse('2026-08-31');
    $transferredAt = Carbon::parse('2026-08-24 11:49:30');
    $afterTransfer = Carbon::parse('2026-08-27'); // Thursday, past, after transfer

    expect(AttendanceAbsence::isUnrecordedAbsence($afterTransfer, $today, null, true, null, $transferredAt))
        ->toBeTrue();
});

it('uses the LATEST of joining, active_since and transfer as the floor', function (): void {
    $today = Carbon::parse('2026-08-31');
    $joining = Carbon::parse('2024-01-01');       // old original hire
    $activeSince = Carbon::parse('2026-06-01');    // a reactivation
    $transferredAt = Carbon::parse('2026-08-24 11:49:30'); // latest

    // A weekday between active_since and the transfer is before the LATEST floor
    // (the transfer) → not an absence at this company.
    expect(AttendanceAbsence::isUnrecordedAbsence(Carbon::parse('2026-08-20'), $today, $joining, true, $activeSince, $transferredAt))
        ->toBeFalse();
    // A weekday after the transfer → an absence.
    expect(AttendanceAbsence::isUnrecordedAbsence(Carbon::parse('2026-08-27'), $today, $joining, true, $activeSince, $transferredAt))
        ->toBeTrue();
});
