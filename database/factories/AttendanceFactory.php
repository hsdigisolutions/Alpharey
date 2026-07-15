<?php

namespace Database\Factories;

use App\Enums\AttendanceMode;
use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'date' => fake()->dateTimeThisMonth()->format('Y-m-d'),
            'mode' => AttendanceMode::Hourly,
            'check_in' => '09:00',
            'check_out' => '17:00',
            'break_hours' => 1,
            'deduct_break' => true,
            'hours_worked' => 7,
            'overtime_hours' => 0,
            'status' => AttendanceStatus::Present,
            'wage_type_snapshot' => 'hourly',
            'wage_rate_snapshot' => 15,
            'hourly_rate_snapshot' => 15,
            'total_amount' => 105,
            'is_paid' => false,
        ];
    }
}
