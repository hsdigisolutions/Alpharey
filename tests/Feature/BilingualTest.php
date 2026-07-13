<?php

use App\Models\Company;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('ships both language dictionaries with every page', function (): void {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->component('Auth/Login')
        ->where('lang.es.nav.dashboard', 'Panel')
        ->where('lang.en.nav.dashboard', 'Dashboard')
        ->where('lang.es.auth.welcome_back', 'Bienvenido de vuelta')
        ->where('lang.en.auth.welcome_back', 'Welcome back'));
});

it('defaults to Spanish primary with English secondary', function (): void {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->where('locale.primary', 'es')
        ->where('locale.secondary', 'en'));
});

it('switches the guest locale per session', function (): void {
    $this->post('/locale', ['locale' => 'en'])->assertRedirect();

    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->where('locale.primary', 'en')
        ->where('locale.secondary', 'es'));
});

it('persists the language preference on the account', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create(['locale' => 'es']);

    $this->actingAs($user)->post('/locale', ['locale' => 'en'])->assertRedirect();

    expect($user->fresh()->locale)->toBe('en');
});

it('uses the saved account preference as primary language', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create(['locale' => 'en']);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('locale.primary', 'en')
        ->where('locale.secondary', 'es'));
});

it('rejects unsupported locales', function (): void {
    $this->from('/login')->post('/locale', ['locale' => 'fr'])
        ->assertSessionHasErrors('locale');
});

it('exposes the VAT dropdown labels in both languages', function (): void {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->where('lang.es.vat.general', 'IVA General 21%')
        ->where('lang.en.vat.general', 'VAT Standard 21%')
        ->where('lang.es.vat.not_applicable', 'No aplica')
        ->where('lang.en.vat.not_applicable', 'Not applicable'));
});
