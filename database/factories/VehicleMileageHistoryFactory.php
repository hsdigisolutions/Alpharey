<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleMileageHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleMileageHistory>
 */
class VehicleMileageHistoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'mileage_value' => 50000,
            'recorded_at' => now()->toDateString(),
        ];
    }
}
