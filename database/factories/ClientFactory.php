<?php

namespace Database\Factories;

use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
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
            'client_type' => fake()->randomElement(ClientType::cases()),
            'contact_person' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'city' => fake()->city(),
            'payment_terms' => 'net30',
            'active' => true,
        ];
    }
}
