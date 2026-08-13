<?php

namespace Database\Factories;

use App\Enums\MeasurementType;
use App\Models\Company;
use App\Models\Measurement;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Measurement>
 */
class MeasurementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'project_id' => Project::factory(),
            'date' => fake()->dateTimeThisMonth()->format('Y-m-d'),
            'quantity' => fake()->randomFloat(2, 1, 200),
            'unit' => fake()->randomElement(['m', 'm2', 'm3', 'kg']),
            'measurement_type' => fake()->randomElement(MeasurementType::cases()),
            // status is derived from `approved` by the model's saving hook, so a
            // fixture that sets only `approved` stays consistent.
            'approved' => false,
        ];
    }

    /**
     * An approved measurement.
     */
    public function approved(): static
    {
        return $this->state(fn (): array => ['approved' => true, 'status' => 'approved']);
    }
}
