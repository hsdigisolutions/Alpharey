<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Notifications\BilingualResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear('login');
});

it('logs in a company user and routes them to the dashboard', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('routes the super admin to the welcome screen after login', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect('/welcome');
});

it('rejects invalid credentials', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rejects deactivated accounts at login', function (): void {
    $user = User::factory()->inactive()->forCompany(Company::factory()->create())->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs out deactivated accounts mid-session', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    $this->actingAs($user);
    $user->update(['active' => false]);

    $this->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('throttles repeated failed logins', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }

    $response = $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('issues a remember cookie when requested', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $guard = auth()->guard('web');
    $response->assertCookie($guard->getRecallerName());
});

it('audits login and logout', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/logout')->assertRedirect('/login');

    expect(AuditLog::query()->where('action', 'login')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'logout')->exists())->toBeTrue();
});

it('sends the bilingual password reset link', function (): void {
    Notification::fake();

    $user = User::factory()->forCompany(Company::factory()->create())->create();

    $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();

    Notification::assertSentTo($user, BilingualResetPassword::class);
});

it('resets the password with a valid token', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-secret-123',
        'password_confirmation' => 'new-secret-123',
    ])->assertRedirect('/login');

    $this->post('/login', ['email' => $user->email, 'password' => 'new-secret-123'])
        ->assertRedirect('/dashboard');
});

it('rejects an invalid reset token', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    $this->from('/reset-password/bad')->post('/reset-password', [
        'token' => 'bad-token',
        'email' => $user->email,
        'password' => 'new-secret-123',
        'password_confirmation' => 'new-secret-123',
    ])->assertSessionHasErrors('email');
});
