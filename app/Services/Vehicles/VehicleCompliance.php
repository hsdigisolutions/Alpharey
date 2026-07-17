<?php

namespace App\Services\Vehicles;

use App\Models\Vehicle;
use App\Services\Documents\DocumentStatus;

/**
 * Screen 21 — insurance and ITV expiries graded on the SAME traffic light as
 * employee and company documents (ok / warn / danger / neutral), and against
 * the same `documents.warn_days` setting.
 *
 * Deliberately reuses DocumentStatus::warnDays() rather than keeping its own
 * threshold: an operator who widens the warning window in Settings expects it
 * to widen everywhere, and two copies of "90 days" would drift.
 *
 * Vehicles are not Documents (no file, no versioning, no exemption), so they
 * cannot go through DocumentStatus itself — only the grading is shared.
 */
class VehicleCompliance
{
    /**
     * The date fields that expire. Keys are lang suffixes under
     * ui.vehicles.*, values are the column.
     *
     * @var array<string, string>
     */
    public const EXPIRY_FIELDS = [
        'insurance' => 'insurance_expiry_date',
        'ita' => 'ita_expiry_date',
    ];

    public function __construct(private readonly DocumentStatus $documents) {}

    /**
     * Grade one expiry field.
     *
     * A missing date is 'neutral', not 'ok': an unknown ITV date is a gap in
     * the record, not a vehicle that is known to be compliant.
     *
     * @return array{0: string, 1: int|null} status + days remaining
     */
    public function of(Vehicle $vehicle, string $field): array
    {
        $date = $vehicle->getAttribute(self::EXPIRY_FIELDS[$field] ?? $field);

        if ($date === null) {
            return ['neutral', null];
        }

        $daysLeft = (int) now()->startOfDay()->diffInDays($date->startOfDay(), false);

        if ($daysLeft < 0) {
            return ['danger', $daysLeft];
        }

        if ($daysLeft <= $this->documents->warnDays()) {
            return ['warn', $daysLeft];
        }

        return ['ok', $daysLeft];
    }

    /**
     * The worst status across a vehicle's expiries — the row indicator.
     */
    public function worst(Vehicle $vehicle): string
    {
        $rank = ['danger' => 3, 'warn' => 2, 'neutral' => 1, 'ok' => 0];
        $worst = 'ok';

        foreach (array_keys(self::EXPIRY_FIELDS) as $field) {
            [$status] = $this->of($vehicle, $field);

            if ($rank[$status] > $rank[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }

    /**
     * Every expiry status for a vehicle, for the detail screen.
     *
     * @return array<string, array{status: string, days: int|null, date: string|null}>
     */
    public function all(Vehicle $vehicle): array
    {
        $out = [];

        foreach (self::EXPIRY_FIELDS as $field => $column) {
            [$status, $days] = $this->of($vehicle, $field);

            $out[$field] = [
                'status' => $status,
                'days' => $days,
                'date' => $vehicle->getAttribute($column)?->toDateString(),
            ];
        }

        return $out;
    }
}
