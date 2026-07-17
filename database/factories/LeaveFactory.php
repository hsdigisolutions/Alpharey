<?php

namespace Database\Factories;

use App\Enums\LeaveStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Leave>
 */
class LeaveFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'leave_category_id' => LeaveCategory::factory(),
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'total_days' => '1',
            'reason' => 'Asunto personal',
            'status' => LeaveStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => LeaveStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }
}
