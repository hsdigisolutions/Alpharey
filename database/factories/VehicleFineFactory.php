<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Vehicle;
use App\Models\VehicleFine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleFine>
 */
class VehicleFineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'company_id' => Company::factory(),
            'employee_id' => null,
            'fine_date' => $this->faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'amount' => (string) $this->faker->randomFloat(2, 50, 600),
            'description' => $this->faker->sentence(),
            'authority' => 'DGT',
            'charged_to' => 'company',
            'paid' => false,
            'created_by' => null,
        ];
    }
}
