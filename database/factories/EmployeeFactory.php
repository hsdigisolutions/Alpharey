<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\WageType;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'company_id' => Company::factory(),
            'employee_code' => sprintf('EF-%05d', $sequence),
            'full_name' => fake()->name(),
            'nif' => fake()->bothify('########?'),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => fake()->phoneNumber(),
            'city' => fake()->city(),
            'department' => fake()->randomElement(['Obra', 'Oficina', 'Logística']),
            'designation' => fake()->randomElement(['Oficial 1ª', 'Peón', 'Encargado', 'Administrativo']),
            'joining_date' => fake()->dateTimeBetween('-4 years', '-1 month')->format('Y-m-d'),
            'active' => true,
            'is_contracted' => true,
            'wage_type' => fake()->randomElement(WageType::cases()),
            'wage_rate' => (string) fake()->randomFloat(2, 9, 28),
            'base_salary' => (string) fake()->randomFloat(2, 1300, 2600),
            'payment_method' => PaymentMethod::BankTransfer,
            'iban' => 'ES'.fake()->numerify('######################'),
            'bank_name' => fake()->randomElement(['BBVA', 'Santander', 'CaixaBank']),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
