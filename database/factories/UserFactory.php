<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::User,
            'company_id' => null,
            'locale' => 'es',
            'active' => true,
            'remember_token' => Str::random(10),
            // Two-step verification is mandatory (SECURITY.md §1), so a normal
            // account IS an enrolled one — a factory user without it would be
            // half-configured and bounce off RequireTwoFactor. Tests that
            // exercise enrolment itself use the pendingTwoFactor() state.
            'two_factor_secret' => (new Google2FA)->generateSecretKey(),
            'two_factor_recovery_codes' => app(TwoFactorService::class)->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ];
    }

    /**
     * A user who has not set up their second factor yet — the state every real
     * account starts in, and the one the enrolment flow is built for.
     */
    public function pendingTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::SuperAdmin,
            'company_id' => null,
        ]);
    }

    public function companyAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::CompanyAdmin,
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}
