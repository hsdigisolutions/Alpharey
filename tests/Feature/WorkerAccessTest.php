<?php

use App\Enums\Module;
use App\Enums\PermissionAction;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Worker PWA — Phase A foundations.
 *
 * A worker account is the narrowest thing in the system: it reaches its own
 * attendance and nothing else. These tests pin the wall in BOTH directions,
 * because the whole safety of exempting workers from two-step verification
 * rests on that reach being genuinely tiny.
 */
beforeEach(function (): void {
    RateLimiter::clear('login');

    $this->company = Company::factory()->create();
});

/** A worker login properly linked to an employee record. */
function workerFor(Company $company, array $userAttributes = []): User
{
    $user = User::factory()->create(array_merge([
        'role' => UserRole::Worker,
        'company_id' => $company->id,
        'password' => 'password',
    ], $userAttributes));

    $employee = Employee::factory()->forCompany($company)->create();
    $employee->user_id = $user->id;
    $employee->save();

    return $user;
}

it('lets a worker sign in with password alone — no second factor', function (): void {
    // The exemption is the whole reason crews will use the app. UserFactory
    // enrols by default, so this worker HAS a secret — and must still skip the
    // challenge, because the exemption is by ROLE, not by enrolment state.
    $worker = workerFor($this->company);

    $this->post('/login', ['email' => $worker->email, 'password' => 'password'])
        ->assertRedirect(route('worker.home'));

    $this->assertAuthenticatedAs($worker);
});

it('opens the worker home for a linked worker', function (): void {
    $worker = workerFor($this->company);

    $this->actingAs($worker)->get('/worker')->assertOk();
});

it('refuses the worker app to an account with no employee record', function (): void {
    // A half-finished setup: the login exists but was never linked. Every
    // worker screen is about "my attendance" — without an employee there is
    // no `my`, so this is a clear 403 rather than an empty app.
    $orphan = User::factory()->create([
        'role' => UserRole::Worker,
        'company_id' => $this->company->id,
    ]);

    $this->actingAs($orphan)->get('/worker')->assertForbidden();
});

it('refuses the worker app to everyone who is not a worker', function (): void {
    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();

    $this->actingAs($admin)->get('/worker')->assertForbidden();
});

it('grants a worker no module permission at all', function (): void {
    // Refused at the engine, so a matrix row created for one by mistake still
    // grants nothing. Every module x every action.
    $worker = workerFor($this->company);

    foreach (Module::cases() as $module) {
        foreach (PermissionAction::cases() as $action) {
            expect(Gate::forUser($worker)->allows("{$module->value}.{$action->value}"))
                ->toBeFalse("worker unexpectedly allowed {$module->value}.{$action->value}");
        }
    }
});

it('bounces a worker off CRM screens back to their own app', function (): void {
    $worker = workerFor($this->company);

    foreach (['/dashboard', '/employees', '/invoices', '/payroll', '/attendance'] as $crmUrl) {
        $this->actingAs($worker)->get($crmUrl)->assertRedirect(route('worker.home'));
    }
});

it('keeps the worker app closed to guests', function (): void {
    $this->get('/worker')->assertRedirect(route('login'));
});

it('ties one login to exactly one employee', function (): void {
    // Sharing an account would put two people's attendance on one payslip.
    $worker = workerFor($this->company);
    $second = Employee::factory()->forCompany($this->company)->create();

    $second->user_id = $worker->id;

    expect(fn () => $second->save())->toThrow(QueryException::class);
});
