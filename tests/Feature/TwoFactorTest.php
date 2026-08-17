<?php

use App\Models\Company;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-step verification, mandatory on every login (SECURITY.md §1).
 *
 * The behaviours worth breaking the build over: a correct password alone must
 * never produce a usable session, recovery codes must be single use, and a
 * disabled account must not slip through the gap between the two steps.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->service = app(TwoFactorService::class);
});

/** A user already through enrolment, with a known secret. */
function enrolledUser(Company $company): User
{
    $user = User::factory()->companyAdmin()->forCompany($company)->create([
        'password' => 'password',
    ]);

    $secret = app(TwoFactorService::class)->startEnrolment($user);
    app(TwoFactorService::class)->confirmEnrolment($user, (new Google2FA)->getCurrentOtp($secret));

    return $user->fresh();
}

function currentOtpFor(User $user): string
{
    return (new Google2FA)->getCurrentOtp($user->two_factor_secret);
}

it('reuses the pending secret across setup reloads so the QR stays stable', function (): void {
    // Regression: regenerating the secret on every setup open rotated it out
    // from under a phone that had scanned it → every code read "invalid".
    $user = User::factory()->pendingTwoFactor()->forCompany($this->company)->create();

    $first = $this->service->startEnrolment($user);
    $second = $this->service->startEnrolment($user->fresh());
    $third = $this->service->startEnrolment($user->fresh());

    expect($second)->toBe($first)->and($third)->toBe($first);

    // A code from that stable secret confirms enrolment.
    expect($this->service->confirmEnrolment($user->fresh(), (new Google2FA)->getCurrentOtp($first)))
        ->not->toBeNull();
});

it('mints a fresh secret for a new enrolment after the previous one is confirmed', function (): void {
    $user = enrolledUser($this->company); // already confirmed
    $confirmedSecret = $user->two_factor_secret;

    // Starting a brand-new enrolment (e.g. after an admin reset cleared it) must
    // not silently reuse the old confirmed secret.
    $user->two_factor_confirmed_at = null;
    $user->save();
    $fresh = $this->service->startEnrolment($user->fresh());

    // It reuses the still-present secret (unconfirmed) — stable — but a truly
    // cleared account gets a new one.
    expect($fresh)->toBe($confirmedSecret);

    $user->two_factor_secret = null;
    $user->save();
    $brandNew = $this->service->startEnrolment($user->fresh());
    expect($brandNew)->not->toBe($confirmedSecret);
});

it('does not log a user in on the password alone', function (): void {
    $user = enrolledUser($this->company);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.challenge'));

    // The password was right, but there is still no session.
    $this->assertGuest();
});

it('completes the login once a valid code is given', function (): void {
    $user = enrolledUser($this->company);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor/challenge', ['code' => currentOtpFor($user)])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong code and leaves the user signed out', function (): void {
    $user = enrolledUser($this->company);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor/challenge', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('accepts a recovery code but only once', function (): void {
    $user = enrolledUser($this->company);
    $codes = $user->two_factor_recovery_codes;
    $code = $codes[0];

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor/challenge', ['code' => $code])->assertRedirect();
    $this->assertAuthenticatedAs($user);

    // The same slip of paper must not work twice.
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor/challenge', ['code' => $code])->assertSessionHasErrors('code');

    $this->assertGuest();
    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(count($codes) - 1);
});

it('refuses an account disabled between the two steps', function (): void {
    $user = enrolledUser($this->company);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    // Admin disables them while they are reaching for their phone.
    $user->forceFill(['active' => false])->save();

    $this->post('/two-factor/challenge', ['code' => currentOtpFor($user)])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('sends a user who has not enrolled to the setup screen', function (): void {
    $user = User::factory()->pendingTwoFactor()->companyAdmin()->forCompany($this->company)->create(['password' => 'password']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    // Logged in, but confined: any other screen bounces to setup.
    $this->get('/dashboard')->assertRedirect(route('two-factor.setup'));
    $this->get('/employees')->assertRedirect(route('two-factor.setup'));
    $this->get('/two-factor/setup')->assertOk();
});

it('lets a user finish enrolment and then reach the app', function (): void {
    $user = User::factory()->pendingTwoFactor()->companyAdmin()->forCompany($this->company)->create(['password' => 'password']);
    $this->actingAs($user);

    $this->get('/two-factor/setup')->assertOk();

    $secret = $user->fresh()->two_factor_secret;
    $this->post('/two-factor/setup', ['code' => (new Google2FA)->getCurrentOtp($secret)])
        ->assertRedirect(route('two-factor.recovery'));

    expect(app(TwoFactorService::class)->isEnrolled($user->fresh()))->toBeTrue();

    $this->get('/dashboard')->assertOk();
});

it('will not confirm enrolment with a wrong code', function (): void {
    $user = User::factory()->pendingTwoFactor()->companyAdmin()->forCompany($this->company)->create(['password' => 'password']);
    $this->actingAs($user);
    $this->get('/two-factor/setup');

    $this->post('/two-factor/setup', ['code' => '000000'])->assertSessionHasErrors('code');

    expect(app(TwoFactorService::class)->isEnrolled($user->fresh()))->toBeFalse();
});

it('keeps the challenge screen unreachable without a pending login', function (): void {
    $this->get('/two-factor/challenge')->assertRedirect(route('login'));
});

it('lets a Super Admin reset a lost second factor', function (): void {
    $sa = User::factory()->superAdmin()->create();
    $target = enrolledUser($this->company);

    // enrol the SA too, so the middleware does not divert them
    $secret = app(TwoFactorService::class)->startEnrolment($sa);
    app(TwoFactorService::class)->confirmEnrolment($sa, (new Google2FA)->getCurrentOtp($secret));

    $this->actingAs($sa->fresh())
        ->post("/admin/permissions/{$target->id}/reset-2fa")
        ->assertRedirect();

    // Cleared, so they are forced through enrolment again.
    expect(app(TwoFactorService::class)->isEnrolled($target->fresh()))->toBeFalse();
});

it('does not let a company admin reset someone elses second factor', function (): void {
    $admin = enrolledUser($this->company);
    $target = enrolledUser($this->company);

    $this->actingAs($admin)
        ->post("/admin/permissions/{$target->id}/reset-2fa")
        ->assertForbidden();

    expect(app(TwoFactorService::class)->isEnrolled($target->fresh()))->toBeTrue();
});

it('never serialises the secret or recovery codes', function (): void {
    $user = enrolledUser($this->company);

    $json = $user->toArray();

    expect($json)->not->toHaveKey('two_factor_secret')
        ->and($json)->not->toHaveKey('two_factor_recovery_codes');
});
