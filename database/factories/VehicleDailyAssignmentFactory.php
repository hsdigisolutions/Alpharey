<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Vehicle;
use App\Models\VehicleDailyAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleDailyAssignment>
 */
class VehicleDailyAssignmentFactory extends Factory
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
            'assigned_date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'notes' => null,
            'created_by' => null,
        ];
    }
}
