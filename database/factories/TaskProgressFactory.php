<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\ProductionTask;
use App\Models\TaskProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskProgress>
 */
class TaskProgressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'production_task_id' => ProductionTask::factory(),
            'employee_id' => Employee::factory(),
            'date' => fake()->dateTimeThisMonth()->format('Y-m-d'),
            'quantity' => fake()->randomFloat(2, 1, 50),
        ];
    }
}
