<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;

/**
 * The project's people (site manager / foreman / safety / coordinator) are links
 * to EMPLOYEE records of the acting company. The legacy free-text columns stay
 * as a display fallback. Tenancy is enforced by OwnCompanyEmployee.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);
    $this->manager = Employee::factory()->forCompany($this->company)->create([
        'full_name' => 'Carlos Martínez', 'designation' => 'Maestro', 'mobile' => '+34 611 222 333',
    ]);
});

it('stores the manager employee ids on create', function (): void {
    $foreman = Employee::factory()->forCompany($this->company)->create();

    $this->post('/projects', [
        'name' => 'Obra Central', 'status' => 'active', 'priority' => 'medium',
        'site_manager_id' => $this->manager->id, 'foreman_id' => $foreman->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = Project::withoutGlobalScopes()->where('name', 'Obra Central')->firstOrFail();
    expect($project->site_manager_id)->toBe($this->manager->id)
        ->and($project->foreman_id)->toBe($foreman->id)
        ->and($project->safety_id)->toBeNull();
});

it('allows a null manager (no one assigned)', function (): void {
    $this->post('/projects', [
        'name' => 'Sin jefe', 'status' => 'active', 'priority' => 'medium',
        'site_manager_id' => null,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Project::withoutGlobalScopes()->where('name', 'Sin jefe')->firstOrFail()->site_manager_id)->toBeNull();
});

it('rejects an employee from another company', function (): void {
    $other = Company::factory()->create();
    $foreign = Employee::factory()->forCompany($other)->create();

    $this->post('/projects', [
        'name' => 'Cross', 'status' => 'active', 'priority' => 'medium',
        'site_manager_id' => $foreign->id,
    ])->assertSessionHasErrors('site_manager_id');

    expect(Project::withoutGlobalScopes()->where('name', 'Cross')->exists())->toBeFalse();
});

it('updates the manager on an existing project', function (): void {
    $project = Project::factory()->forCompany($this->company)->create(['status' => 'active', 'priority' => 'medium']);

    $this->put("/projects/{$project->id}", [
        'name' => $project->name, 'status' => 'active', 'priority' => 'medium',
        'site_manager_id' => $this->manager->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($project->fresh()->site_manager_id)->toBe($this->manager->id);
});

it('ships the resolved site manager (name · designation · phone) to the detail page', function (): void {
    $project = Project::factory()->forCompany($this->company)->create([
        'site_manager_id' => $this->manager->id, 'jefe_de_obra' => null,
    ]);

    $this->get("/projects/{$project->id}")
        ->assertInertia(fn ($page) => $page
            ->where('project.site_manager.employee_id', $this->manager->id)
            ->where('project.site_manager.name', 'Carlos Martínez')
            ->where('project.site_manager.designation', 'Maestro')
            ->where('project.site_manager.phone', '+34 611 222 333')
            ->where('project.site_manager_id', $this->manager->id));
});

it('falls back to the legacy free-text value when no employee is linked', function (): void {
    $project = Project::factory()->forCompany($this->company)->create([
        'site_manager_id' => null, 'jefe_de_obra' => 'Old Text Manager',
    ]);

    $this->get("/projects/{$project->id}")
        ->assertInertia(fn ($page) => $page
            ->where('project.site_manager.employee_id', null)
            ->where('project.site_manager.name', 'Old Text Manager'));
});

it('offers only active own-company employees in the form options', function (): void {
    Employee::factory()->forCompany($this->company)->create(['full_name' => 'Inactive Bob', 'active' => false]);
    $otherCompany = Company::factory()->create();
    Employee::factory()->forCompany($otherCompany)->create(['full_name' => 'Foreign Sue']);

    $project = Project::factory()->forCompany($this->company)->create();

    $this->get("/projects/{$project->id}")
        ->assertInertia(fn ($page) => $page
            ->has('employeeOptions')
            ->where('employeeOptions', fn ($opts) => collect($opts)->pluck('name')->contains('Carlos Martínez')
                && ! collect($opts)->pluck('name')->contains('Inactive Bob')
                && ! collect($opts)->pluck('name')->contains('Foreign Sue')));
});
