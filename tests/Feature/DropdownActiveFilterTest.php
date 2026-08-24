<?php

use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Change 4 — selection dropdowns show ACTIVE entities only, while index-page
 * list filters keep showing all statuses. Dual-use pages ship a separate
 * active-only "form*" prop; the original all-options prop feeds the filter.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();

    $this->activeEmployee = Employee::factory()->for($this->company)->create(['active' => true, 'full_name' => 'Active Worker']);
    $this->inactiveEmployee = Employee::factory()->for($this->company)->create(['active' => false, 'full_name' => 'Inactive Worker']);

    $this->activeClient = Client::factory()->create(['active' => true, 'name' => 'Active Client']);
    $this->inactiveClient = Client::factory()->create(['active' => false, 'name' => 'Inactive Client']);

    $this->activeProject = Project::factory()->for($this->company)->create(['status' => ProjectStatus::Active, 'name' => 'Active Project']);
    $this->inProgressProject = Project::factory()->for($this->company)->create(['status' => ProjectStatus::InProgress, 'name' => 'InProgress Project']);
    $this->completedProject = Project::factory()->for($this->company)->create(['status' => ProjectStatus::Completed, 'name' => 'Completed Project']);
    $this->onHoldProject = Project::factory()->for($this->company)->create(['status' => ProjectStatus::OnHold, 'name' => 'OnHold Project']);
});

/** @return list<int> */
function idsOf(array $rows): array
{
    return array_map(fn ($r) => (int) $r['id'], $rows);
}

it('model scopeActive returns only selectable rows', function (): void {
    $this->actingAs($this->admin);

    expect(Employee::query()->active()->pluck('id')->all())
        ->toContain($this->activeEmployee->id)
        ->not->toContain($this->inactiveEmployee->id);

    expect(Client::query()->active()->pluck('id')->all())
        ->toContain($this->activeClient->id)
        ->not->toContain($this->inactiveClient->id);

    $projectIds = Project::query()->active()->pluck('id')->all();
    expect($projectIds)
        ->toContain($this->activeProject->id)
        ->toContain($this->inProgressProject->id)
        ->not->toContain($this->completedProject->id)
        ->not->toContain($this->onHoldProject->id);
});

it('inventory selection dropdowns exclude inactive employees and non-active projects', function (): void {
    $this->actingAs($this->admin)->get('/inventory')->assertInertia(fn (Assert $p) => $p
        ->where('employees', fn ($e) => in_array($this->activeEmployee->id, idsOf($e->toArray()), true)
            && ! in_array($this->inactiveEmployee->id, idsOf($e->toArray()), true))
        ->where('projects', fn ($pr) => in_array($this->activeProject->id, idsOf($pr->toArray()), true)
            && ! in_array($this->completedProject->id, idsOf($pr->toArray()), true))
    );
});

it('expenses ships active-only formProjects but keeps all projects for the filter', function (): void {
    $this->actingAs($this->admin)->get('/expenses')->assertInertia(fn (Assert $p) => $p
        ->where('formProjects', fn ($pr) => ! in_array($this->completedProject->id, idsOf($pr->toArray()), true))
        ->where('projects', fn ($pr) => in_array($this->completedProject->id, idsOf($pr->toArray()), true))
        ->where('employees', fn ($e) => ! in_array($this->inactiveEmployee->id, idsOf($e->toArray()), true))
        ->etc()
    );
});

it('invoices split projects and clients: form lists active, filter lists all', function (): void {
    $this->actingAs($this->admin)->get('/invoices')->assertInertia(fn (Assert $p) => $p
        ->where('formProjects', fn ($pr) => ! in_array($this->completedProject->id, idsOf($pr->toArray()), true))
        ->where('projects', fn ($pr) => in_array($this->completedProject->id, idsOf($pr->toArray()), true))
        ->where('formClients', fn ($c) => ! in_array($this->inactiveClient->id, idsOf($c->toArray()), true))
        ->where('clients', fn ($c) => in_array($this->inactiveClient->id, idsOf($c->toArray()), true))
        ->etc()
    );
});

it('leave ships active-only formEmployees but keeps all employees for the filter', function (): void {
    $this->actingAs($this->admin)->get('/leave')->assertInertia(fn (Assert $p) => $p
        ->where('formEmployees', fn ($e) => ! in_array($this->inactiveEmployee->id, idsOf($e->toArray()), true))
        ->where('employees', fn ($e) => in_array($this->inactiveEmployee->id, idsOf($e->toArray()), true))
        ->etc()
    );
});

it('attendance ships active-only formProjects but keeps all projects for the roster filter', function (): void {
    $this->actingAs($this->admin)->get('/attendance')->assertInertia(fn (Assert $p) => $p
        ->where('formProjects', fn ($pr) => in_array($this->activeProject->id, idsOf($pr->toArray()), true)
            && ! in_array($this->completedProject->id, idsOf($pr->toArray()), true))
        ->where('projects', fn ($pr) => in_array($this->completedProject->id, idsOf($pr->toArray()), true))
        ->etc()
    );
});

it('projects page ships active-only activeClients but keeps all clients for the filter', function (): void {
    $this->actingAs($this->admin)->get('/projects')->assertInertia(fn (Assert $p) => $p
        ->where('activeClients', fn ($c) => ! in_array($this->inactiveClient->id, idsOf($c->toArray()), true))
        ->where('filterOptions.clients', fn ($c) => in_array($this->inactiveClient->id, idsOf($c->toArray()), true))
        ->etc()
    );
});
