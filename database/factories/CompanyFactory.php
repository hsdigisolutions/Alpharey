<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'name' => fake()->company(),
            'cif' => fake()->bothify('?########'),
            'province' => fake()->randomElement(['Madrid', 'Barcelona', 'Valencia', 'Sevilla', 'Bizkaia']),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'website' => null,
            'logo_path' => null,
            'status' => 'active',
            'notes' => null,
        ];
    }
}
