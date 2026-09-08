<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyCard>
 */
class CompanyCardFactory extends Factory
{
    protected $model = CompanyCard::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'label' => fake()->unique()->words(2, true),
            'last_four' => (string) fake()->numberBetween(1000, 9999),
            'active' => true,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}
