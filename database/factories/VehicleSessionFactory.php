<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleSession>
 */
class VehicleSessionFactory extends Factory
{
    protected $model = VehicleSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'employee_id' => Employee::factory(),
            'company_id' => Company::factory(),
            'taken_at' => now()->subHour(),
            'returned_at' => null,
            'starting_mileage' => $this->faker->numberBetween(10_000, 100_000),
            'starting_fuel_level' => $this->faker->numberBetween(20, 100),
            'ending_mileage' => null,
            'ending_fuel_level' => null,
            'fuel_added_litres' => '0.00',
            'return_notes' => null,
            'overdue_alerted' => false,
        ];
    }

    public function closed(): static
    {
        return $this->state(function (array $attrs): array {
            $endMileage = ($attrs['starting_mileage'] ?? 50000) + $this->faker->numberBetween(10, 200);

            return [
                'returned_at' => now(),
                'ending_mileage' => $endMileage,
                'km_driven' => $endMileage - ($attrs['starting_mileage'] ?? 50000),
            ];
        });
    }
}
