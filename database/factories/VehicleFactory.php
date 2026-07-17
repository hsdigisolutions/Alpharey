<?php

namespace Database\Factories;

use App\Enums\FuelType;
use App\Enums\VehicleOwnership;
use App\Models\Company;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'plate_number' => strtoupper($this->faker->unique()->bothify('####???')),
            'brand' => 'Renault',
            'model' => 'Kangoo',
            'year' => 2020,
            'ownership' => VehicleOwnership::Company,
            'active' => true,
            'fuel_type' => FuelType::Diesel,
            'current_mileage' => 50000,
            'maintenance_cost_total' => '0',
        ];
    }

    /**
     * A vehicle whose paperwork is about to lapse — the case the compliance
     * scan exists for.
     */
    public function expiringSoon(int $days = 15): static
    {
        return $this->state(fn (): array => [
            'insurance_expiry_date' => now()->addDays($days)->toDateString(),
            'ita_expiry_date' => now()->addDays($days)->toDateString(),
        ]);
    }
}
