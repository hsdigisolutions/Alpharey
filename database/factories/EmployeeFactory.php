<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\WageType;
use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkerConsent;
use App\Support\WorkerPrivacyNotice;
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
            // Always well in the past (≥2 years) so a date-sensitive test that
            // seeds a recent month's attendance never has the worker "join"
            // AFTER those dates — the -1 month upper bound used to make monthly
            // pro-rata tests flaky as the clock advanced.
            'joining_date' => fake()->dateTimeBetween('-6 years', '-2 years')->format('Y-m-d'),
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

    /**
     * A worker who has already been shown and acknowledged the current
     * geolocation/selfie notice — the state most worker-punch tests want,
     * since a fresh worker is blocked behind the notice until they do.
     */
    public function privacyAcknowledged(bool $gps = true, bool $photo = true): static
    {
        return $this->afterCreating(function (Employee $employee) use ($gps, $photo): void {
            WorkerConsent::query()->create([
                'employee_id' => $employee->id,
                'user_id' => $employee->user_id,
                'company_id' => $employee->company_id,
                'consent_version' => WorkerPrivacyNotice::currentVersion(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
                'consented_at' => now(),
                'timezone' => 'Europe/Madrid',
                'consent_attendance' => true,
                'consent_gps' => $gps,
                'consent_photo' => $photo,
                'consent_text_shown' => WorkerPrivacyNotice::canonicalText('es'),
                'language' => 'es',
            ]);
        });
    }
}
