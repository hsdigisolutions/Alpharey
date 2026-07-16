<?php

namespace Database\Factories;

use App\Enums\AdvanceStatus;
use App\Models\Advance;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Advance>
 */
class AdvanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'amount' => '200',
            'reason' => fake()->sentence(4),
            'status' => AdvanceStatus::Pending,
            'request_date' => now()->toDateString(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => AdvanceStatus::Approved]);
    }
}
