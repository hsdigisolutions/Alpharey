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
            'role' => UserRole::Manager,
            'company_id' => null,
            'locale' => 'es',
            'active' => true,
            'remember_token' => Str::random(10),
            // Two-step verification is mandatory (SECURITY.md §1), so a normal
            // account IS an enrolled one — a factory user without it would be
            // half-configured and bounce off RequireTwoFactor.
            'two_factor_secret' => (new Google2FA)->generateSecretKey(),
            'two_factor_recovery_codes' => app(TwoFactorService::class)->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ];
    }

    /**
     * A user who has not set up their second factor yet.
     */
    public function pendingTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

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

    /**
     * Admin role: broad management access, bypasses the permission matrix.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * @deprecated Use admin() — kept so existing tests compile unchanged.
     */
    public function companyAdmin(): static
    {
        return $this->admin();
    }

    /**
     * Manager role: per-module permission rows, company-scoped.
     */
    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Manager,
        ]);
    }

    /**
     * Worker role: PWA only, no CRM access, exempt from 2FA in practice
     * (EnsureWorker wall). Still enrolled so RequireTwoFactor doesn't intercept
     * CRM routes if the guard somehow passes.
     */
    public function worker(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Worker,
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
