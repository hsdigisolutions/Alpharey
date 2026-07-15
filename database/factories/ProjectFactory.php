<?php

namespace Database\Factories;

use App\Enums\BillingType;
use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'company_id' => Company::factory(),
            'client_id' => Client::factory(),
            'code' => sprintf('PF-%05d', $seq),
            'name' => 'Obra '.fake()->streetName(),
            'project_type' => fake()->randomElement(['Reforma', 'Obra nueva', 'Mantenimiento']),
            'status' => fake()->randomElement(ProjectStatus::cases()),
            'priority' => fake()->randomElement(ProjectPriority::cases()),
            'billing_type' => fake()->randomElement(BillingType::cases()),
            'start_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'budget' => fake()->randomFloat(2, 10000, 500000),
            'jefe_de_obra' => fake()->name(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}
