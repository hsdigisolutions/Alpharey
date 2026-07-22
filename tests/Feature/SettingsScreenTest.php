<?php

use App\Models\Company;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->superAdmin = User::factory()->superAdmin()->create();
    $this->companyAdmin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

it('is visible to admins only', function (): void {
    $this->actingAs($this->companyAdmin)->get('/admin/settings')->assertOk();

    $user = User::factory()->forCompany($this->company)->create();
    $this->actingAs($user)->get('/admin/settings')->assertForbidden();
});

it('ships SMTP settings to the super admin only', function (): void {
    $this->actingAs($this->superAdmin)->get('/admin/settings')
        ->assertInertia(fn (Assert $page) => $page->has('mail')->where('canManageMail', true));

    $this->actingAs($this->companyAdmin)->get('/admin/settings')
        ->assertInertia(fn (Assert $page) => $page->where('mail', null)->where('canManageMail', false));
});

it('updates general settings', function (): void {
    $this->actingAs($this->companyAdmin)->put('/admin/settings/general', [
        'app_name' => 'AlphaRey CRM',
        'default_locale' => 'es',
        'timezone' => 'Europe/Madrid',
        'session_timeout_minutes' => 45,
    ])->assertRedirect();

    $settings = app(SettingsService::class);

    expect($settings->get('general.app_name'))->toBe('AlphaRey CRM')
        ->and($settings->get('general.session_timeout_minutes'))->toBe(45);
});

it('applies the configured session timeout on the next request', function (): void {
    app(SettingsService::class)->set('general.session_timeout_minutes', 45);

    $this->actingAs($this->companyAdmin)->get('/dashboard')->assertOk();

    expect(config('session.lifetime'))->toBe(45);
});

it('rejects out-of-range session timeouts', function (): void {
    $this->actingAs($this->companyAdmin)->put('/admin/settings/general', [
        'app_name' => 'AlphaRey',
        'default_locale' => 'es',
        'timezone' => 'Europe/Madrid',
        'session_timeout_minutes' => 2,
    ])->assertSessionHasErrors('session_timeout_minutes');
});

it('forbids company admins from touching SMTP settings', function (): void {
    $this->actingAs($this->companyAdmin)->put('/admin/settings/mail', [
        'host' => 'smtp.alpharey.com',
        'port' => 587,
        'encryption' => 'tls',
        'from_name' => 'AlphaRey',
        'from_address' => 'noreply@alpharey.com',
    ])->assertForbidden();
});

it('stores the SMTP password encrypted and never echoes it back', function (): void {
    $this->actingAs($this->superAdmin)->put('/admin/settings/mail', [
        'host' => 'smtp.alpharey.com',
        'port' => 587,
        'username' => 'noreply@alpharey.com',
        'password' => 'super-secret',
        'encryption' => 'tls',
        'from_name' => 'AlphaRey',
        'from_address' => 'noreply@alpharey.com',
    ])->assertRedirect();

    $stored = app(SettingsService::class)->get('mail.password');

    expect($stored)->not->toBeNull()
        ->and($stored)->not->toContain('super-secret')
        ->and(Crypt::decryptString($stored))->toBe('super-secret');

    $this->get('/admin/settings')->assertInertia(fn (Assert $page) => $page
        ->where('mail.has_password', true)
        ->missing('mail.password'));
});

it('keeps the stored password when the field is left blank', function (): void {
    $this->actingAs($this->superAdmin)->put('/admin/settings/mail', [
        'host' => 'smtp.alpharey.com', 'port' => 587, 'username' => 'x',
        'password' => 'first-secret', 'encryption' => 'tls',
        'from_name' => 'AlphaRey', 'from_address' => 'noreply@alpharey.com',
    ]);

    $this->put('/admin/settings/mail', [
        'host' => 'smtp.alpharey.com', 'port' => 465, 'username' => 'x',
        'password' => '', 'encryption' => 'ssl',
        'from_name' => 'AlphaRey', 'from_address' => 'noreply@alpharey.com',
    ]);

    expect(Crypt::decryptString(app(SettingsService::class)->get('mail.password')))->toBe('first-secret');
});

it('sends a test email from the super admin', function (): void {
    $this->actingAs($this->superAdmin)->post('/admin/settings/mail/test', [
        'to' => 'prueba@alpharey.com',
    ])->assertRedirect()->assertSessionHas('success');
});
