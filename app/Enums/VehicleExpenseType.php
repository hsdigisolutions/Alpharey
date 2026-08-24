<?php

namespace App\Enums;

/**
 * The kind of vehicle expense — decides which vehicle_* table the approved
 * expense is mirrored into (Part D/E): fine → vehicle_fines, maintenance →
 * vehicle_maintenance_histories, fuel → vehicle_fuel_records.
 */
enum VehicleExpenseType: string
{
    case Fine = 'fine';
    case Maintenance = 'maintenance';
    case Fuel = 'fuel';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t): string => $t->value, self::cases());
    }
}
