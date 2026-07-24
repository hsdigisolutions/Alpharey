<?php

use App\Enums\Module;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Multi-company switching for Admins/Managers: the user_company pivot is the
 * authority for which companies a non-SA user may act in, the session
 * selection is validated against it on every request, and a Manager's module
 * rights follow the ACTIVE company (rows are per user × company).
 */
beforeEach(function (): void {
    $this->companyA = Company::factory()->create(['name' => 'Empresa A']);
    $this->companyB = Company::factory()->create(['name' => 'Empresa B']);
    $this->companyC = Company::factory()->create(['name' => 'Empresa C']);
});

function assignToCompany(User $user, Company $company): void
{
    DB::table('user_company')->insertOrIgnore([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'created_at' => now(),
    ]);
}

it('lets a manager switch to a pivot-assigned company and scopes data to it', function (): void {
    $manager = User::factory()->forCompany($this->companyA)->create();
    assignToCompany($manager, $this->companyA);
    assignToCompany($manager, $this->companyB);

    $this->actingAs($manager)
        ->post("/company/{$this->companyB->id}/switch")
        ->assertRedirect('/dashboard');

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('company.id', $this->companyB->id));
});

it('refuses a switch to a company the user is not assigned to', function (): void {
    $manager = User::factory()->forCompany($this->companyA)->create();
    assignToCompany($manager, $this->companyA);

    $this->actingAs($manager)
        ->post("/company/{$this->companyC->id}/switch")
        ->assertForbidden();

    // The active company is untouched.
    $this->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('company.id', $this->companyA->id));
});

it('invalidates a stale selection when the assignment is revoked', function (): void {
    $manager = User::factory()->forCompany($this->companyA)->create();
    assignToCompany($manager, $this->companyA);
    assignToCompany($manager, $this->companyB);

    $this->actingAs($manager)->post("/company/{$this->companyB->id}/switch");

    // Assignment withdrawn AFTER the selection was stored in the session.
    DB::table('user_company')
        ->where('user_id', $manager->id)
        ->where('company_id', $this->companyB->id)
        ->delete();

    // Re-authenticate with a freshly loaded model: in production every
    // request loads the user (and their pivot) from scratch — the test
    // harness reuses one instance, which would keep the relation cached.
    $this->actingAs($manager->fresh());

    // Falls back to the primary company — the session value alone grants nothing.
    $this->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('company.id', $this->companyA->id));
});

it('evaluates manager module rights against the ACTIVE company, not the primary', function (): void {
    $manager = User::factory()->forCompany($this->companyA)->create();
    assignToCompany($manager, $this->companyA);
    assignToCompany($manager, $this->companyB);

    // employees.view granted ONLY for company B.
    UserModulePermission::query()->create([
        'user_id' => $manager->id,
        'company_id' => $this->companyB->id,
        'module' => Module::Employees->value,
        'can_view' => true,
        'granted_by' => $manager->id,
    ]);

    // On the primary company (A): no grant there → denied.
    $this->actingAs($manager)->get('/employees')->assertForbidden();

    // Acting in company B: the B-row applies.
    $this->post("/company/{$this->companyB->id}/switch");
    $this->get('/employees')->assertOk();
});

it('shows the switched company its own rows only', function (): void {
    $manager = User::factory()->forCompany($this->companyA)->create();
    assignToCompany($manager, $this->companyA);
    assignToCompany($manager, $this->companyB);

    foreach ([$this->companyA, $this->companyB] as $company) {
        UserModulePermission::query()->create([
            'user_id' => $manager->id,
            'company_id' => $company->id,
            'module' => Module::Employees->value,
            'can_view' => true,
            'granted_by' => $manager->id,
        ]);
    }

    $inA = Employee::factory()->for($this->companyA)->create(['full_name' => 'Trabajador A']);
    $inB = Employee::factory()->for($this->companyB)->create(['full_name' => 'Trabajador B']);

    $this->actingAs($manager)->post("/company/{$this->companyB->id}/switch");

    // Tenancy follows the switch: B's employee is visible, A's is a 404.
    $this->get("/employees/{$inB->id}")->assertOk();
    $this->get("/employees/{$inA->id}")->assertNotFound();
});

it('blocks workers from the switch endpoint entirely', function (): void {
    $worker = User::factory()->forCompany($this->companyA)->create(['role' => UserRole::Worker]);

    // DenyWorkers bounces them to their own app before the controller runs.
    $this->actingAs($worker)
        ->post("/company/{$this->companyA->id}/switch")
        ->assertRedirect(route('worker.home'));
});
