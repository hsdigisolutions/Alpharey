<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\DeploymentCharge;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Deployments\DeploymentChargeService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    // companyA = host (the acting admin's company); companyB = home
    $this->host = Company::factory()->create();
    $this->home = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->host)->create();

    $this->hostProject = Project::factory()->forCompany($this->host)->create();
    $this->homeEmployee = Employee::factory()->forCompany($this->home)->create(['full_name' => 'Ana Torres']);
});

function deploymentPayload(array $overrides = []): array
{
    return array_merge([
        'employee_id' => test()->homeEmployee->id,
        'home_company_id' => test()->home->id,
        'project_id' => test()->hostProject->id,
        'deployment_start' => '2026-07-01',
        'deployment_end' => '2026-07-31',
        'billing_method' => 'option_a',
        'rate_during_deployment' => '18',
        'rate_type' => 'hourly',
        'split_pct' => 100,
    ], $overrides);
}

it('renders the deployments index', function (): void {
    $this->actingAs($this->admin)->get('/deployments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Deployments/Index')->has('deployments'));
});

it('denies deployments without view permission', function (): void {
    $user = User::factory()->forCompany($this->host)->create();

    $this->actingAs($user)->get('/deployments')->assertForbidden();
});

it('creates a deployment with the host as the acting company', function (): void {
    $this->actingAs($this->admin)->post('/deployments', deploymentPayload())->assertRedirect();

    $deployment = EmployeeDeployment::query()->firstOrFail();

    expect($deployment->host_company_id)->toBe($this->host->id)
        ->and($deployment->home_company_id)->toBe($this->home->id)
        ->and($deployment->employee_id)->toBe($this->homeEmployee->id)
        ->and($deployment->status->value)->toBe('active')
        ->and($deployment->approved_by)->toBe($this->admin->id);
});

it('rejects a billing method other than Option A (never automate cesión ilegal)', function (): void {
    $this->actingAs($this->admin)
        ->post('/deployments', deploymentPayload(['billing_method' => 'option_b']))
        ->assertSessionHasErrors('billing_method');

    expect(EmployeeDeployment::query()->count())->toBe(0);
});

it('blocks a second overlapping deployment for the same employee', function (): void {
    $this->actingAs($this->admin)->post('/deployments', deploymentPayload())->assertRedirect();

    $this->actingAs($this->admin)
        ->post('/deployments', deploymentPayload(['deployment_start' => '2026-07-15', 'deployment_end' => '2026-08-15']))
        ->assertSessionHasErrors('deployment_start');

    expect(EmployeeDeployment::query()->count())->toBe(1);
});

it('rejects a project that is not the host company', function (): void {
    $otherProject = Project::factory()->forCompany($this->home)->create();

    $this->actingAs($this->admin)
        ->post('/deployments', deploymentPayload(['project_id' => $otherProject->id]))
        ->assertNotFound();
});

it('rejects an employee who does not belong to the chosen home company', function (): void {
    $wrongEmployee = Employee::factory()->forCompany($this->host)->create();

    $this->actingAs($this->admin)
        ->post('/deployments', deploymentPayload(['employee_id' => $wrongEmployee->id]))
        ->assertStatus(422);
});

it('lists available employees of a home company (gated, cross-company)', function (): void {
    Employee::factory()->forCompany($this->home)->create(['full_name' => 'Luis Vega']);

    $response = $this->actingAs($this->admin)
        ->getJson("/deployments/available-employees?home_company_id={$this->home->id}");

    $response->assertOk();
    expect(collect($response->json())->pluck('full_name'))
        ->toContain('Ana Torres')
        ->toContain('Luis Vega');
});

it('generates an Option A cross-charge = EXACT frozen cost on completion', function (): void {
    $this->actingAs($this->admin)->post('/deployments', deploymentPayload([
        'deployment_start' => '2026-07-01', 'deployment_end' => '2026-07-03', 'rate_during_deployment' => '10',
    ]))->assertRedirect();

    $deployment = EmployeeDeployment::query()->firstOrFail();

    // Deployed employee's HOST-project days during the window — the exact cost
    // is the SUM of their FROZEN day totals (no margin, no rate×units guesswork).
    Attendance::factory()->create([
        'company_id' => $this->host->id, 'employee_id' => $this->homeEmployee->id,
        'project_id' => $this->hostProject->id, 'date' => '2026-07-01',
        'status' => 'present', 'hours_worked' => '8', 'total_amount' => '80',
    ]);
    Attendance::factory()->create([
        'company_id' => $this->host->id, 'employee_id' => $this->homeEmployee->id,
        'project_id' => $this->hostProject->id, 'date' => '2026-07-02',
        'status' => 'present', 'hours_worked' => '6', 'total_amount' => '60',
    ]);

    $this->actingAs($this->admin)->post("/deployments/{$deployment->id}/complete")->assertRedirect();

    $charge = DeploymentCharge::query()->where('employee_deployment_id', $deployment->id)->firstOrFail();

    // Exact cost = 80 + 60 = 140 (the frozen totals), locked on completion.
    expect((float) $charge->amount)->toBe(140.0)
        ->and($charge->status)->toBe('locked')
        ->and($charge->home_company_id)->toBe($this->home->id)
        ->and($charge->host_company_id)->toBe($this->host->id)
        ->and($deployment->fresh()->status->value)->toBe('completed');

    // The host's internal expense carries the amount but NEVER the worker name.
    $expense = Expense::withoutGlobalScopes()->whereKey($charge->expense_id)->firstOrFail();
    expect($expense->company_id)->toBe($this->host->id)
        ->and((float) $expense->total)->toBe(140.0)
        ->and($expense->notes)->not->toContain($this->homeEmployee->full_name);
});

it('accrues the cross-charge LIVE as attendance is logged for an active deployment', function (): void {
    // A daily wage so a full day prices non-zero (the exact cost the host owes).
    $this->homeEmployee->update(['wage_type' => 'daily', 'daily_wage' => '90']);

    $this->actingAs($this->admin)->post('/deployments', deploymentPayload([
        'deployment_start' => '2026-07-01', 'deployment_end' => '2026-07-31', 'rate_during_deployment' => '10',
    ]))->assertRedirect();
    $deployment = EmployeeDeployment::query()->firstOrFail();

    // Log one host-project day via the service → the pending charge appears.
    app(AttendanceService::class); // resolve once
    $this->actingAs($this->admin)->post('/attendance', [
        'employee_id' => $this->homeEmployee->id, 'project_id' => $this->hostProject->id,
        'date' => '2026-07-01', 'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
    ])->assertRedirect();

    $charge = DeploymentCharge::query()->where('employee_deployment_id', $deployment->id)->first();
    expect($charge)->not->toBeNull()
        ->and($charge->status)->toBe('pending');             // still active → pending
    $firstAmount = (float) $charge->amount;
    expect($firstAmount)->toBeGreaterThan(0.0);

    // Log a second day → the SAME charge grows (live accrual).
    $this->actingAs($this->admin)->post('/attendance', [
        'employee_id' => $this->homeEmployee->id, 'project_id' => $this->hostProject->id,
        'date' => '2026-07-02', 'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
    ])->assertRedirect();

    expect((float) $charge->fresh()->amount)->toBeGreaterThan($firstAmount)
        ->and(DeploymentCharge::query()->where('employee_deployment_id', $deployment->id)->count())->toBe(1);
});

it('never generates a charge for a non-Option-A deployment', function (): void {
    // Build directly (the controller forbids non-A via validation) to prove the
    // engine itself refuses to automate Option B/C.
    $deployment = EmployeeDeployment::factory()->create([
        'billing_method' => 'option_b', 'home_company_id' => $this->home->id,
        'host_company_id' => $this->host->id, 'employee_id' => $this->homeEmployee->id,
        'project_id' => $this->hostProject->id,
    ]);

    expect(app(DeploymentChargeService::class)->generateCharge($deployment))->toBeNull();
});

it('is visible to the home company but not to an unrelated company', function (): void {
    $deployment = EmployeeDeployment::factory()->create([
        'home_company_id' => $this->home->id, 'host_company_id' => $this->host->id,
        'employee_id' => $this->homeEmployee->id, 'project_id' => $this->hostProject->id,
    ]);

    // A home-company admin can see it
    $homeAdmin = User::factory()->companyAdmin()->forCompany($this->home)->create();
    $this->actingAs($homeAdmin)->get('/deployments')
        ->assertInertia(fn (Assert $page) => $page->has('deployments.data', 1));

    // An unrelated company sees nothing
    $stranger = Company::factory()->create();
    $strangerAdmin = User::factory()->companyAdmin()->forCompany($stranger)->create();
    $this->actingAs($strangerAdmin)->get('/deployments')
        ->assertInertia(fn (Assert $page) => $page->has('deployments.data', 0));
});

it('shows a deployed employee on the host attendance grid — real name + Deployed badge', function (): void {
    EmployeeDeployment::factory()->create([
        'home_company_id' => $this->home->id, 'host_company_id' => $this->host->id,
        'employee_id' => $this->homeEmployee->id, 'project_id' => $this->hostProject->id,
        'deployment_start' => now()->startOfMonth()->toDateString(),
        'deployment_end' => now()->endOfMonth()->toDateString(),
    ]);

    // The host attendance grid shows the deployed worker's REAL name + a
    // Deployed badge + home company (client decision 2026-09 — anonymisation is
    // only on the passive Deployments screen, NOT this grid). Their wage stays
    // hidden and the profile is still tenancy-protected.
    $this->actingAs($this->admin)->get('/attendance')
        ->assertInertia(fn (Assert $page) => $page
            ->where('employees', fn ($employees) => collect($employees)
                ->contains(fn ($e) => $e['deployed'] === true
                    && $e['full_name'] === $this->homeEmployee->full_name
                    && $e['home_company'] === $this->home->name)));
});

it('refuses to deploy a soft-deleted employee', function (): void {
    // The server-side re-check dropped ALL global scopes, so a trashed
    // employee id smuggled into the request could still open a posting.
    $this->homeEmployee->delete();

    $this->actingAs($this->admin)
        ->post('/deployments', deploymentPayload())
        ->assertNotFound();

    expect(EmployeeDeployment::query()->count())->toBe(0);
});

it('books attendance for a worker deployed into the acting company', function (): void {
    // The host logs the deployed worker's days (Phase 5). The employee row
    // lives under the HOME company, so a tenant-scoped findOrFail would 404
    // the legitimate case the deployment exists to permit.
    $this->actingAs($this->admin)->post('/deployments', deploymentPayload())->assertRedirect();

    $response = $this->actingAs($this->admin)->post('/attendance', [
        'employee_id' => $this->homeEmployee->id,
        'date' => '2026-07-06',
        'mode' => 'project_based',
        'hours_worked' => 8,
        'status' => 'present',
    ]);

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors();

    expect(Attendance::withoutGlobalScopes()
        ->where('employee_id', $this->homeEmployee->id)
        ->where('company_id', $this->host->id)
        ->exists())->toBeTrue();
});

it('refuses to cancel or re-complete a completed deployment', function (): void {
    // A completed posting already produced its cross-charge + host expense —
    // cancelling it would orphan that money on a posting marked never-run.
    $this->actingAs($this->admin)->post('/deployments', deploymentPayload())->assertRedirect();
    $deployment = EmployeeDeployment::query()->firstOrFail();

    $this->actingAs($this->admin)->post("/deployments/{$deployment->id}/complete")->assertRedirect();

    $this->actingAs($this->admin)->post("/deployments/{$deployment->id}/cancel")->assertStatus(422);
    $this->actingAs($this->admin)->post("/deployments/{$deployment->id}/complete")->assertStatus(422);

    expect($deployment->fresh()->status->value)->toBe('completed');
});

/**
 * Change 2B — editing an ACTIVE deployment (employee, rate structure, end date).
 */
function activeDeployment(array $overrides = []): EmployeeDeployment
{
    return EmployeeDeployment::create(array_merge([
        'employee_id' => test()->homeEmployee->id,
        'home_company_id' => test()->home->id,
        'host_company_id' => test()->host->id,
        'project_id' => test()->hostProject->id,
        'deployment_start' => '2026-07-01',
        'deployment_end' => '2026-07-31',
        'billing_method' => 'option_a',
        'rate_during_deployment' => '18',
        'rate_type' => 'hourly',
        'split_pct' => 100,
        'status' => 'active',
        'approved_by' => test()->admin->id,
    ], $overrides));
}

it('edits an active deployment — employee, rate structure and end date', function (): void {
    $dep = activeDeployment();
    $newEmployee = Employee::factory()->forCompany($this->home)->create(['full_name' => 'Luis Gómez']);

    $this->actingAs($this->admin)->put("/deployments/{$dep->id}", [
        'employee_id' => $newEmployee->id,
        'rate_type' => 'daily',
        'rate_during_deployment' => '90',
        'split_pct' => 80,
        'deployment_end' => '2026-08-15',
        'notes' => 'Extended + repriced',
    ])->assertRedirect();

    $dep->refresh();
    expect($dep->employee_id)->toBe($newEmployee->id)
        ->and($dep->rate_type->value)->toBe('daily')
        ->and((float) $dep->rate_during_deployment)->toBe(90.0)
        ->and((float) $dep->split_pct)->toBe(80.0)
        ->and($dep->deployment_end->toDateString())->toBe('2026-08-15')
        ->and($dep->notes)->toBe('Extended + repriced')
        ->and($dep->status->value)->toBe('active'); // still active
});

it('refuses to edit a completed or cancelled deployment (422)', function (): void {
    foreach (['completed', 'cancelled'] as $status) {
        $dep = activeDeployment(['status' => $status]);
        $this->actingAs($this->admin)->put("/deployments/{$dep->id}", [
            'employee_id' => $this->homeEmployee->id, 'rate_type' => 'daily',
            'rate_during_deployment' => '90', 'split_pct' => 100,
        ])->assertStatus(422);
    }
});

it('blocks changing the employee once attendance is logged, but still allows a rate edit', function (): void {
    $dep = activeDeployment();
    Attendance::factory()->create([
        'company_id' => $this->host->id, 'employee_id' => $this->homeEmployee->id,
        'project_id' => $this->hostProject->id, 'date' => '2026-07-10', 'status' => 'present',
    ]);
    $other = Employee::factory()->forCompany($this->home)->create();

    // Employee swap is blocked…
    $this->actingAs($this->admin)->put("/deployments/{$dep->id}", [
        'employee_id' => $other->id, 'rate_type' => 'hourly', 'rate_during_deployment' => '18', 'split_pct' => 100,
    ])->assertSessionHasErrors('employee_id');
    expect($dep->fresh()->employee_id)->toBe($this->homeEmployee->id);

    // …but editing the rate (same employee) is fine.
    $this->actingAs($this->admin)->put("/deployments/{$dep->id}", [
        'employee_id' => $this->homeEmployee->id, 'rate_type' => 'daily', 'rate_during_deployment' => '75', 'split_pct' => 100,
    ])->assertRedirect();
    expect($dep->fresh()->rate_type->value)->toBe('daily')->and((float) $dep->fresh()->rate_during_deployment)->toBe(75.0);
});

it('blocks shortening the end date before a day already logged', function (): void {
    $dep = activeDeployment();
    Attendance::factory()->create([
        'company_id' => $this->host->id, 'employee_id' => $this->homeEmployee->id,
        'project_id' => $this->hostProject->id, 'date' => '2026-07-20', 'status' => 'present',
    ]);

    $this->actingAs($this->admin)->put("/deployments/{$dep->id}", [
        'employee_id' => $this->homeEmployee->id, 'rate_type' => 'hourly', 'rate_during_deployment' => '18',
        'split_pct' => 100, 'deployment_end' => '2026-07-15', // before the logged 20th
    ])->assertSessionHasErrors('deployment_end');
    expect($dep->fresh()->deployment_end->toDateString())->toBe('2026-07-31');
});

it('rejects a replacement employee from another company (422)', function (): void {
    $dep = activeDeployment();
    $foreign = Employee::factory()->forCompany(Company::factory()->create())->create();

    $this->actingAs($this->admin)->put("/deployments/{$dep->id}", [
        'employee_id' => $foreign->id, 'rate_type' => 'hourly', 'rate_during_deployment' => '18', 'split_pct' => 100,
    ])->assertStatus(422);
    expect($dep->fresh()->employee_id)->toBe($this->homeEmployee->id);
});

it('cannot edit a deployment when acting for a company that is neither home nor host (404)', function (): void {
    $dep = activeDeployment();
    $outsider = User::factory()->companyAdmin()->forCompany(Company::factory()->create())->create();

    $this->actingAs($outsider)->put("/deployments/{$dep->id}", [
        'employee_id' => $this->homeEmployee->id, 'rate_type' => 'hourly', 'rate_during_deployment' => '18', 'split_pct' => 100,
    ])->assertNotFound();
});

it('denies editing without deployments.edit permission', function (): void {
    $dep = activeDeployment();
    $user = User::factory()->forCompany($this->host)->create();

    $this->actingAs($user)->put("/deployments/{$dep->id}", [
        'employee_id' => $this->homeEmployee->id, 'rate_type' => 'hourly', 'rate_during_deployment' => '18', 'split_pct' => 100,
    ])->assertForbidden();
});

/*
 * Visibility layer (2026-09): the HOME side sees full cross-charge detail; the
 * HOST side sees a minimal, anonymised presence only.
 */

it('shows the home viewer full detail and the host viewer a minimal presence row', function (): void {
    $this->actingAs($this->admin)->post('/deployments', deploymentPayload([
        'deployment_start' => '2026-07-01', 'deployment_end' => '2026-07-31', 'rate_type' => 'daily',
    ]))->assertRedirect();
    Attendance::factory()->create([
        'company_id' => $this->host->id, 'employee_id' => $this->homeEmployee->id,
        'project_id' => $this->hostProject->id, 'date' => '2026-07-01',
        'status' => 'present', 'total_amount' => '90',
    ]);

    // HOST admin (acting = host): minimal — no worker name, no amount.
    $this->actingAs($this->admin)->get('/deployments')
        ->assertInertia(fn (Assert $p) => $p
            ->where('deployments.data.0.viewer', 'host')
            ->where('deployments.data.0.days_present', 1)
            ->missing('deployments.data.0.employee')
            ->missing('deployments.data.0.accrued_cost'));

    // HOME admin (acting = home): full detail incl. the €90 owed.
    $homeAdmin = User::factory()->companyAdmin()->forCompany($this->home)->create();
    $this->actingAs($homeAdmin)->get('/deployments')
        ->assertInertia(fn (Assert $p) => $p
            ->where('deployments.data.0.viewer', 'home')
            ->where('deployments.data.0.employee', 'Ana Torres')
            ->where('deployments.data.0.accrued_cost', 90));
});

it('badges the worker at home and shows the real name on the host attendance grid', function (): void {
    $this->actingAs($this->admin)->post('/deployments', deploymentPayload([
        'deployment_start' => now()->startOfMonth()->toDateString(),
        'deployment_end' => now()->endOfMonth()->toDateString(),
    ]))->assertRedirect();

    // HOME Employees list → "Desplegado a {host}" badge datum.
    $homeAdmin = User::factory()->companyAdmin()->forCompany($this->home)->create();
    $this->actingAs($homeAdmin)->get('/employees')
        ->assertInertia(fn (Assert $p) => $p->where('employees.data.0.deployed_to', $this->host->name));

    // HOME employee detail → activeDeployment badge payload present.
    $this->actingAs($homeAdmin)->get("/employees/{$this->homeEmployee->id}")
        ->assertInertia(fn (Assert $p) => $p->where('activeDeployment.host_company', $this->host->name));

    // HOST attendance grid → the deployed-in worker's REAL name shows with the
    // Deployed flag (anonymisation is only on the Deployments screen).
    $this->actingAs($this->admin)->get('/attendance')
        ->assertInertia(fn (Assert $p) => $p->where('employees', fn ($emps) => collect($emps)
            ->contains(fn ($e) => ($e['deployed'] ?? false) && $e['full_name'] === $this->homeEmployee->full_name)));
});

it('fills a deployed-out worker on the HOME main attendance grid — read-only, no phantom absence', function (): void {
    $homeAdmin = User::factory()->companyAdmin()->forCompany($this->home)->create();
    EmployeeDeployment::factory()->create([
        'home_company_id' => $this->home->id, 'host_company_id' => $this->host->id,
        'employee_id' => $this->homeEmployee->id, 'project_id' => $this->hostProject->id,
        'deployment_start' => '2026-07-01', 'deployment_end' => '2026-07-31',
    ]);
    // Host-logged day for the home worker (company = HOST), a past weekday.
    Attendance::factory()->create([
        'company_id' => $this->host->id, 'employee_id' => $this->homeEmployee->id,
        'project_id' => $this->hostProject->id, 'date' => '2026-07-07',
        'status' => 'present', 'day_type' => 'full', 'total_amount' => '90',
    ]);

    // Shizukani's MAIN grid now shows 7 July FILLED for the worker — marked
    // deployed-out (read-only), from the host, present (NOT a phantom absence),
    // and appearing exactly once.
    $eid = $this->homeEmployee->id;
    $this->actingAs($homeAdmin)->get('/attendance?month=2026-07')
        ->assertInertia(fn (Assert $p) => $p
            // 7 July is the DEPLOYED-OUT cell — present, from the host, read-only —
            // NOT the phantom absence it used to be.
            ->where("grid.{$eid}.7.deployed_out", true)
            ->where("grid.{$eid}.7.deployed_from", $this->host->name)
            ->where("grid.{$eid}.7.status", 'present')
            // Folded into the summary as a present day (complete picture). The
            // OTHER unrecorded weekdays are still genuine absences — the point is
            // the deployed day is not one of them.
            ->where("summary.{$eid}.days_present", 1));
});
