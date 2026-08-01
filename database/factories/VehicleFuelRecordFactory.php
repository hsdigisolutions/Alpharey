<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Vehicle;
use App\Models\VehicleFuelRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleFuelRecord>
 */
class VehicleFuelRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $litres = round($this->faker->randomFloat(1, 20, 60), 1);
        $cpp = round($this->faker->randomFloat(3, 1.4, 1.9), 3);

        return [
            'vehicle_id' => Vehicle::factory(),
            'company_id' => Company::factory(),
            'employee_id' => null,
            'fuel_date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'litres' => (string) $litres,
            'cost_per_litre' => (string) $cpp,
            'total_cost' => (string) round($litres * $cpp, 2),
            'mileage_at_fill' => $this->faker->numberBetween(10000, 200000),
            'payment_method' => 'card',
            'notes' => null,
            'created_by' => null,
        ];
    }
}
