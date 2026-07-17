<?php

namespace Database\Factories;

use App\Enums\VehicleAssignmentType;
use App\Models\Employee;
use App\Models\EmployeeVehicleAssignment;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeVehicleAssignment>
 */
class EmployeeVehicleAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'vehicle_id' => Vehicle::factory(),
            'type' => VehicleAssignmentType::Company,
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
        ];
    }

    /**
     * The worker's own car: no fleet vehicle to point at, by definition.
     */
    public function own(): static
    {
        return $this->state(fn (): array => [
            'type' => VehicleAssignmentType::Own,
            'vehicle_id' => null,
        ]);
    }
}
