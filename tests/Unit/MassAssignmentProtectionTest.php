<?php

use App\Models\Attendance;
use App\Models\CommissionReportEntry;
use App\Models\SubcontractorWorker;

/**
 * M2 (audit) — server-owned money/pay columns must not be mass-assignable, so a
 * future ->fill($request->all()) can never let a client set its own frozen pay
 * rate, a computed subcontractor total, or a commission amount. These are all
 * set by their services via direct assignment; the guard is pinned here so a
 * well-meaning "add it back to $fillable" cannot silently reopen the hole.
 */
it('never mass-assigns the frozen wage snapshots on attendance', function (): void {
    $a = new Attendance;

    expect($a->isFillable('wage_type_snapshot'))->toBeFalse()
        ->and($a->isFillable('wage_rate_snapshot'))->toBeFalse()
        ->and($a->isFillable('hourly_rate_snapshot'))->toBeFalse();

    $a->fill(['wage_rate_snapshot' => '999.99', 'hourly_rate_snapshot' => '999.99', 'wage_type_snapshot' => 'daily']);

    expect($a->wage_rate_snapshot)->toBeNull()
        ->and($a->hourly_rate_snapshot)->toBeNull()
        ->and($a->wage_type_snapshot)->toBeNull();
});

it('never mass-assigns the computed subcontractor total', function (): void {
    $w = new SubcontractorWorker;

    expect($w->isFillable('total_agreed'))->toBeFalse();

    $w->fill(['total_agreed' => '999999']);

    expect($w->total_agreed)->toBeNull();
});

it('never mass-assigns commission amounts', function (): void {
    $e = new CommissionReportEntry;

    expect($e->isFillable('original_amount'))->toBeFalse()
        ->and($e->isFillable('adjusted_amount'))->toBeFalse();

    $e->fill(['original_amount' => '999999', 'adjusted_amount' => '999999']);

    expect($e->original_amount)->toBeNull()
        ->and($e->adjusted_amount)->toBeNull();
});
