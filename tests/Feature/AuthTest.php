<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\BilingualResetPassword;
use App\Services\Workers\WorkerAccountService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    RateLimiter::clear('login');
});

/**
 * Login is two steps now (SECURITY.md §1): the password parks the user at the
 * challenge, and a TOTP completes it. This posts the second step.
 */
function passTwoFactor(User $user): TestResponse
{
    return test()->post('/two-factor/challenge', [
        'code' => (new Google2FA)->getCurrentOtp($user->two_factor_secret),
    ]);
}

it('logs in a company user and routes them to the dashboard', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    // Step one hands off to the challenge, granting no session yet.
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();

    passTwoFactor($user)->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('routes the super admin to the welcome screen after login', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $this->post('/login', ['email' => $admin->email, 'password' => 'password']);

    passTwoFactor($admin)->assertRedirect('/welcome');
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

    // "Remember me" is carried across the challenge and applied when the
    // session is actually created — i.e. on the second step, not the first.
    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $response = passTwoFactor($user);

    $guard = auth()->guard('web');
    $response->assertCookie($guard->getRecallerName());
});

it('forces a long-lived remember-me session for a worker, no box ticked', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->companyAdmin()->forCompany($company)->create();
    $this->actingAs($admin);
    app(WorkerAccountService::class)->grant($employee, 'crew@example.com', 'site-pass-123');
    auth()->logout();

    // A worker logs in WITHOUT ticking "remember me".
    $response = $this->post('/login', ['email' => 'crew@example.com', 'password' => 'site-pass-123'])
        ->assertRedirect(route('worker.home'));

    // The 30-day recaller (remember-me) cookie is set anyway, and the token is
    // persisted — so a phone left idle on site re-authenticates silently.
    $response->assertCookie(auth()->guard('web')->getRecallerName());
    expect($employee->fresh()->user->remember_token)->not->toBeNull();
});

it('does not force remember-me for a non-worker login', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    // A CRM user without ticking remember: no recaller cookie is issued (the
    // factory seeds a remember_token, so the cookie — not the column — is the
    // signal that remember-me was actually applied).
    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $response->assertCookieMissing(auth()->guard('web')->getRecallerName());
});

it('audits login and logout', function (): void {
    $user = User::factory()->forCompany(Company::factory()->create())->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    passTwoFactor($user);
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

    // A reset never bypasses the second factor: the new password still only
    // gets you as far as the challenge.
    $this->post('/login', ['email' => $user->email, 'password' => 'new-secret-123'])
        ->assertRedirect(route('two-factor.challenge'));

    passTwoFactor($user)->assertRedirect('/dashboard');
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
