<?php

use App\Enums\DayType;
use App\Enums\WageType;
use App\Models\Attendance;

/**
 * displayHoursNet() is the day-type-aware DISPLAY helper (Step 2 of the
 * 08:00–17:00 working-hours change). It must deduct the unpaid break from a
 * FULL day only, leave half / per-meter as real time on site, and echo the
 * hourly pay field verbatim. It is DISPLAY ONLY — these are not pay numbers,
 * so they are pinned here so a future edit cannot silently change what the
 * grids, reports and PWA show.
 *
 * The branches that take an explicit break argument need no database — they
 * are pure clock arithmetic. The container-default path is covered by a
 * feature test (it reads the per-company setting through the service).
 */
function att(array $attrs): Attendance
{
    $a = new Attendance;

    foreach ($attrs as $key => $value) {
        $a->{$key} = $value;
    }

    return $a;
}

it('deducts the break from a full 08:00–17:00 (9 h) day → 8 h', function (): void {
    $row = att(['day_type' => DayType::Full, 'check_in' => '08:00', 'check_out' => '17:00']);

    expect($row->displayHoursNet(60))->toBe(8.0);
});

it('leaves a legacy full 09:00–17:00 (8 h) day at 8 h — already net, no break taken', function (): void {
    // The break lived inside the span only for the new 9 h shift; an 8 h span
    // is already a full net day, so history must NOT drop to 7 h.
    $row = att(['day_type' => DayType::Full, 'check_in' => '09:00', 'check_out' => '17:00']);

    expect($row->displayHoursNet(60))->toBe(8.0);
});

it('leaves an 08:00–16:30 (8.5 h) full day unchanged — deducting would fall below a full day', function (): void {
    $row = att(['day_type' => DayType::Full, 'check_in' => '08:00', 'check_out' => '16:30']);

    expect($row->displayHoursNet(60))->toBe(8.5);
});

it('deducts the break from a long 10 h full day → 9 h', function (): void {
    $row = att(['day_type' => DayType::Full, 'check_in' => '07:00', 'check_out' => '17:00']);

    expect($row->displayHoursNet(60))->toBe(9.0);
});

it('never deducts a break when the configured break is zero', function (): void {
    $row = att(['day_type' => DayType::Full, 'check_in' => '08:00', 'check_out' => '17:00']);

    expect($row->displayHoursNet(0))->toBe(9.0);
});

it('never deducts a break from a half day', function (): void {
    $row = att(['day_type' => DayType::Half, 'check_in' => '08:00', 'check_out' => '12:00']);

    expect($row->displayHoursNet(60))->toBe(4.0);
});

it('shows the hourly pay field verbatim, ignoring the break and the clock span', function (): void {
    // deduct_break already netted the pay field to 6.5 at write time; the
    // display must not subtract the break a second time, nor use the 9 h span.
    $row = att([
        'day_type' => DayType::Hourly,
        'check_in' => '08:00',
        'check_out' => '17:00',
        'hours_worked' => '6.50',
    ]);

    expect($row->displayHoursNet(60))->toBe(6.5);
});

it('never deducts a break from a per-meter day', function (): void {
    $row = att(['day_type' => DayType::PerMeter, 'check_in' => '08:00', 'check_out' => '17:00']);

    expect($row->displayHoursNet(60))->toBe(9.0);
});

it('grades a legacy null day_type by the wage-type snapshot, like payroll does', function (): void {
    $daily = att(['day_type' => null, 'wage_type_snapshot' => WageType::Daily, 'check_in' => '08:00', 'check_out' => '17:00']);
    $hourly = att(['day_type' => null, 'wage_type_snapshot' => WageType::Hourly, 'check_in' => '08:00', 'check_out' => '17:00', 'hours_worked' => '8.00']);

    // Daily → Full → break deducted (8 h); Hourly → the pay field (8 h), span ignored.
    expect($daily->displayHoursNet(60))->toBe(8.0)
        ->and($hourly->displayHoursNet(60))->toBe(8.0);
});

it('falls back to the stored hours when a full day carries no clock times', function (): void {
    $row = att(['day_type' => DayType::Full, 'hours_worked' => '0.00']);

    expect($row->displayHoursNet(60))->toBe(0.0);
});

it('shows an imported no-clock daily day at its stored hours, never deducting a break', function (): void {
    // The 5,464-row legacy population: null day_type, no clock, daily wage,
    // hours_worked already net. Must be shown verbatim (no phantom −1 h).
    $row = att(['day_type' => null, 'wage_type_snapshot' => WageType::Daily, 'hours_worked' => '8.71']);

    expect($row->displayHoursNet(60))->toBe(8.71);
});
