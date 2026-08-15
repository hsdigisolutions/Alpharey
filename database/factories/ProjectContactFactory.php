<?php

namespace Database\Factories;

use App\Enums\ProjectContactRole;
use App\Models\ProjectContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectContact>
 */
class ProjectContactFactory extends Factory
{
    protected $model = ProjectContact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'role' => fake()->randomElement(ProjectContactRole::cases()),
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
