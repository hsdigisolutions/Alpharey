<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'company_name' => fake()->company(),
            'nif' => fake()->bothify('?########'),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'city' => fake()->city(),
            'active' => true,
        ];
    }
}
