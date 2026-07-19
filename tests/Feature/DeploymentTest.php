<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\DeploymentCharge;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Project;
use App\Models\User;
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

it('generates an Option A cross-charge from host-project hours on completion', function (): void {
    $this->actingAs($this->admin)->post('/deployments', deploymentPayload([
        'deployment_start' => '2026-07-01', 'deployment_end' => '2026-07-03', 'rate_during_deployment' => '10',
    ]))->assertRedirect();

    $deployment = EmployeeDeployment::query()->firstOrFail();

    // Deployed employee logs hours against the HOST project during the window
    Attendance::factory()->create([
        'company_id' => $this->host->id, 'employee_id' => $this->homeEmployee->id,
        'project_id' => $this->hostProject->id, 'date' => '2026-07-01', 'hours_worked' => '8',
    ]);
    Attendance::factory()->create([
        'company_id' => $this->host->id, 'employee_id' => $this->homeEmployee->id,
        'project_id' => $this->hostProject->id, 'date' => '2026-07-02', 'hours_worked' => '6',
    ]);

    $this->actingAs($this->admin)->post("/deployments/{$deployment->id}/complete")->assertRedirect();

    $charge = DeploymentCharge::query()->where('employee_deployment_id', $deployment->id)->firstOrFail();

    // 14h × 10 × 100% = 140
    expect((float) $charge->amount)->toBe(140.0)
        ->and($charge->home_company_id)->toBe($this->home->id)
        ->and($charge->host_company_id)->toBe($this->host->id)
        ->and($deployment->fresh()->status->value)->toBe('completed');
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

it('shows a deployed employee on the host attendance grid with the deployed flag', function (): void {
    EmployeeDeployment::factory()->create([
        'home_company_id' => $this->home->id, 'host_company_id' => $this->host->id,
        'employee_id' => $this->homeEmployee->id, 'project_id' => $this->hostProject->id,
        'deployment_start' => now()->startOfMonth()->toDateString(),
        'deployment_end' => now()->endOfMonth()->toDateString(),
    ]);

    $this->actingAs($this->admin)->get('/attendance')
        ->assertInertia(fn (Assert $page) => $page
            ->where('employees', fn ($employees) => collect($employees)
                ->contains(fn ($e) => $e['deployed'] === true && $e['full_name'] === 'Ana Torres')));
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
