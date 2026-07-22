<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;

/**
 * Granting an employee a login for the mobile PWA (employee detail screen).
 *
 * A worker account is not a normal user: it belongs to exactly one employee,
 * it is created from the employee record rather than the admin user forms, and
 * it can reach nothing but its own attendance.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('creates a linked worker login for an employee', function (): void {
    $this->actingAs($this->admin)
        ->post("/employees/{$this->employee->id}/app-access", [
            'email' => 'obrero@example.com',
            'password' => 'site-pass-123',
        ])->assertRedirect();

    $user = User::query()->where('email', 'obrero@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Worker)
        // The login belongs to the EMPLOYEE's company, never the acting one.
        ->and($user->company_id)->toBe($this->company->id)
        ->and($this->employee->fresh()->user_id)->toBe($user->id);
});

it('lets the new worker actually sign in and reach their app', function (): void {
    $this->actingAs($this->admin)->post("/employees/{$this->employee->id}/app-access", [
        'email' => 'obrero@example.com',
        'password' => 'site-pass-123',
    ]);

    auth()->logout();

    // Password only — the exemption is what makes the app usable on site.
    $this->post('/login', ['email' => 'obrero@example.com', 'password' => 'site-pass-123'])
        ->assertRedirect(route('worker.home'));

    $this->get('/worker')->assertOk();
});

it('refuses an email already in use', function (): void {
    $taken = User::factory()->forCompany($this->company)->create();

    $this->actingAs($this->admin)
        ->post("/employees/{$this->employee->id}/app-access", [
            'email' => $taken->email,
            'password' => 'site-pass-123',
        ])->assertSessionHasErrors('email');

    expect($this->employee->fresh()->user_id)->toBeNull();
});

it('resets the password of an existing login instead of making a second one', function (): void {
    $grant = fn (string $password) => $this->actingAs($this->admin)
        ->post("/employees/{$this->employee->id}/app-access", [
            'email' => 'obrero@example.com',
            'password' => $password,
        ]);

    $grant('first-pass-123');
    $firstId = $this->employee->fresh()->user_id;

    $grant('second-pass-123');

    expect($this->employee->fresh()->user_id)->toBe($firstId)
        ->and(User::query()->where('email', 'obrero@example.com')->count())->toBe(1);

    auth()->logout();
    $this->post('/login', ['email' => 'obrero@example.com', 'password' => 'second-pass-123'])
        ->assertRedirect(route('worker.home'));
});

it('deactivates rather than deletes the login when access is revoked', function (): void {
    // The attendance rows the account created must keep pointing somewhere,
    // and its audit trail has to survive.
    $this->actingAs($this->admin)->post("/employees/{$this->employee->id}/app-access", [
        'email' => 'obrero@example.com',
        'password' => 'site-pass-123',
    ]);

    $userId = $this->employee->fresh()->user_id;

    $this->actingAs($this->admin)
        ->delete("/employees/{$this->employee->id}/app-access")
        ->assertRedirect();

    expect($this->employee->fresh()->user_id)->toBeNull()
        ->and(User::query()->whereKey($userId)->exists())->toBeTrue()
        ->and(User::query()->whereKey($userId)->value('active'))->toBeFalsy();
});

it('denies granting access without the employees edit right', function (): void {
    $viewer = User::factory()->forCompany($this->company)->create();

    $this->actingAs($viewer)
        ->post("/employees/{$this->employee->id}/app-access", [
            'email' => 'obrero@example.com',
            'password' => 'site-pass-123',
        ])->assertForbidden();
});

it('cannot grant access to another company employee', function (): void {
    $foreign = Employee::factory()->forCompany(Company::factory()->create())->create();

    $this->actingAs($this->admin)
        ->post("/employees/{$foreign->id}/app-access", [
            'email' => 'obrero@example.com',
            'password' => 'site-pass-123',
        ])->assertNotFound();
});
