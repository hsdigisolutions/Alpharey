<?php

namespace Database\Factories;

use App\Models\LeaveCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveCategory>
 */
class LeaveCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // null = a group-wide default, which is the common case
            'company_id' => null,
            'key' => $this->faker->unique()->slug(1),
            'name' => 'Vacaciones',
            'default_allocation' => '20',
            'is_paid' => true,
            'active' => true,
        ];
    }

    public function unpaid(): static
    {
        return $this->state(fn (): array => ['is_paid' => false, 'default_allocation' => '0']);
    }
}
