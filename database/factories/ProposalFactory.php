<?php

namespace Database\Factories;

use App\Enums\ProposalStatus;
use App\Models\Client;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        $subtotal = fake()->randomFloat(2, 1000, 50000);

        return [
            'number' => sprintf('PROPF-%05d', $seq),
            'client_id' => Client::factory(),
            'proposal_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(30)->toDateString(),
            'description' => fake()->sentence(),
            'subtotal' => $subtotal,
            'total_amount' => $subtotal,
            'status' => fake()->randomElement(ProposalStatus::cases()),
        ];
    }
}
