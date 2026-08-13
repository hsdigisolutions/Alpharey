<?php

namespace Database\Factories;

use App\Enums\ProductionTaskCategory;
use App\Enums\ProductionTaskStatus;
use App\Models\Company;
use App\Models\ProductionTask;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionTask>
 */
class ProductionTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'project_id' => Project::factory(),
            'name' => fake()->words(3, true),
            'category' => fake()->randomElement(ProductionTaskCategory::cases()),
            'house_number' => fake()->optional()->bothify('C-##'),
            'unit' => fake()->randomElement(['m2', 'm', 'pcs']),
            'unit_price' => fake()->randomFloat(2, 1, 100),
            'planned_quantity' => fake()->randomFloat(2, 50, 500),
            'completed_quantity' => 0,
            'weightage' => 0,
            'status' => ProductionTaskStatus::Open,
        ];
    }
}
