<?php

namespace Database\Factories;

use App\Enums\ProductionTaskCategory;
use App\Models\Company;
use App\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskTemplate>
 */
class TaskTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->words(3, true),
            'category' => fake()->randomElement(ProductionTaskCategory::cases()),
            'unit' => fake()->randomElement(['m2', 'm', 'pcs']),
            'unit_price' => fake()->randomFloat(2, 1, 100),
            'planned_quantity' => fake()->randomFloat(2, 50, 500),
            'weightage' => fake()->randomFloat(2, 0, 25),
            'description' => fake()->optional()->sentence(),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }
}
