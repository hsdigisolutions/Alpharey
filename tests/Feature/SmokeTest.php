<?php

use App\Models\Company;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests from the root to login', function (): void {
    $this->get('/')->assertRedirect('/login');
});

it('redirects authenticated users from the root to the dashboard', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
});

it('renders the login placeholder', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('requires authentication for the dashboard', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('renders the dashboard shell for authenticated users', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.role', 'user'));
});

it('answers the health check', function (): void {
    $this->get('/up')->assertOk();
});

it('fails the legacy import command until the legacy connection is configured', function (): void {
    config(['database.connections.legacy.database' => '']);

    $this->artisan('verto:import-legacy')->assertFailed();
});

it('runs the legacy import command with no importers registered yet', function (): void {
    config(['database.connections.legacy.database' => 'legacy_restored']);

    $this->artisan('verto:import-legacy --dry-run')->assertSuccessful();
});
