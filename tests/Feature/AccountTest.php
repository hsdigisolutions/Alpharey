<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Notifications\BilingualResetPassword;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\Notification;

/**
 * "My Account" self-service (client decisions 2026-07-23): name-only profile
 * edit, password change as a request to a Super Admin, and password-gated
 * self-management of the second factor.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    // Enrolled by default (UserFactory), password 'password' — so the account
    // routes (behind the two_factor gate) are reachable and current_password
    // checks pass.
    $this->user = User::factory()->forCompany($this->company)->create([
        'name' => 'Original Name',
        'email' => 'me@example.com',
    ]);
});

it('shows my account with my details and 2FA status', function (): void {
    $this->actingAs($this->user)->get('/account')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Account/Index')
            ->where('account.email', 'me@example.com')
            ->where('account.two_factor_enabled', true)
            ->where('account.password_reset_requested', false));
});

it('updates my own name only, never my email or role', function (): void {
    $this->actingAs($this->user)->put('/account/profile', ['name' => 'New Name'])
        ->assertRedirect();

    $this->user->refresh();
    expect($this->user->name)->toBe('New Name')
        ->and($this->user->email)->toBe('me@example.com')
        ->and($this->user->role)->toBe(UserRole::User);
});

it('raises a password reset request and notifies every super admin', function (): void {
    Notification::fake();
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($this->user)->post('/account/password-reset-request')->assertRedirect();

    $this->user->refresh();
    expect($this->user->password_reset_requested_at)->not->toBeNull();

    Notification::assertSentTo($superAdmin, SystemNotification::class);
    expect(AuditLog::where('action', 'password_reset_requested')
        ->where('model_id', (string) $this->user->id)->exists())->toBeTrue();
});

it('does not re-notify while a request is already pending', function (): void {
    Notification::fake();
    User::factory()->superAdmin()->create();
    $this->user->forceFill(['password_reset_requested_at' => now()->subDay()])->save();

    $this->actingAs($this->user)->post('/account/password-reset-request')->assertRedirect();

    Notification::assertNothingSent();
});

it('requires the current password to reconfigure 2FA', function (): void {
    $this->actingAs($this->user)->post('/account/two-factor/reconfigure', [
        'current_password' => 'wrong-password',
    ])->assertSessionHasErrors('current_password');

    // Still enrolled — a wrong password changed nothing.
    expect($this->user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('reconfigures 2FA with the correct password and sends me to setup', function (): void {
    $this->actingAs($this->user)->post('/account/two-factor/reconfigure', [
        'current_password' => 'password',
    ])->assertRedirect(route('two-factor.setup'));

    // Momentarily un-enrolled — RequireTwoFactor will hold them on setup.
    expect($this->user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('regenerates recovery codes with the correct password', function (): void {
    $before = $this->user->two_factor_recovery_codes;

    $this->actingAs($this->user)->post('/account/two-factor/recovery-codes', [
        'current_password' => 'password',
    ])->assertRedirect(route('two-factor.recovery'));

    expect($this->user->fresh()->two_factor_recovery_codes)->not->toBe($before);
});

it('lets a super admin send a reset link and clears the pending flag', function (): void {
    Notification::fake();
    $superAdmin = User::factory()->superAdmin()->create();
    $this->user->forceFill(['password_reset_requested_at' => now()])->save();

    $this->actingAs($superAdmin)->post("/admin/permissions/{$this->user->id}/reset-password")
        ->assertRedirect();

    expect($this->user->fresh()->password_reset_requested_at)->toBeNull();
    Notification::assertSentTo($this->user, BilingualResetPassword::class);
});

it('refuses the reset-link lever to a company admin', function (): void {
    $companyAdmin = User::factory()->companyAdmin()->forCompany($this->company)->create();

    $this->actingAs($companyAdmin)->post("/admin/permissions/{$this->user->id}/reset-password")
        ->assertForbidden();
});
