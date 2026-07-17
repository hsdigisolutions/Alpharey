<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleMaintenanceHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleMaintenanceHistory>
 */
class VehicleMaintenanceHistoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'maintenance_type' => 'oil_change',
            'maintenance_date' => now()->toDateString(),
            'vehicle_km' => 50000,
            'cost' => '120',
        ];
    }
}
