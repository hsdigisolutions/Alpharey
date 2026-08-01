<?php

namespace Database\Factories;

use App\Enums\WorkerExpenseStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkerExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerExpense>
 */
class WorkerExpenseFactory extends Factory
{
    protected $model = WorkerExpense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'company_id' => Company::factory(),
            'date' => today()->toDateString(),
            'amount' => $this->faker->randomFloat(2, 5, 200),
            'category' => $this->faker->randomElement(['transport', 'materials', 'tools', 'food', 'other']),
            'description' => $this->faker->sentence(),
            'status' => WorkerExpenseStatus::Pending->value,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => WorkerExpenseStatus::Approved->value,
            'approved_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Not a valid work expense'): static
    {
        return $this->state(fn (): array => [
            'status' => WorkerExpenseStatus::Rejected->value,
            'rejection_reason' => $reason,
            'approved_at' => now(),
        ]);
    }
}
