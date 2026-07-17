<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleHistory>
 */
class VehicleHistoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'employee_id' => null,
            'assigned_from' => now(),
            'assigned_to' => null,
        ];
    }
}
