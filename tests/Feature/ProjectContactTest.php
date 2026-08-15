<?php

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectContact;
use App\Models\User;

/**
 * Client-side project contacts — simple CRUD, company-scoped, gated by
 * projects.edit. A contact is reached through the tenant-scoped project.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);
    $this->project = Project::factory()->forCompany($this->company)->create();
});

it('lets an admin add a client contact', function (): void {
    $this->post("/projects/{$this->project->id}/contacts", [
        'name' => 'Ana Torres', 'role' => 'engineer',
        'phone' => '+34 600 111 222', 'email' => 'ana@cliente.com', 'notes' => 'Prefiere email',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $c = ProjectContact::withoutGlobalScopes()->firstOrFail();
    expect($c->name)->toBe('Ana Torres')
        ->and($c->role->value)->toBe('engineer')
        ->and($c->project_id)->toBe($this->project->id)
        ->and($c->company_id)->toBe($this->company->id);
});

it('ignores a company_id injected in the request', function (): void {
    $other = Company::factory()->create();

    $this->post("/projects/{$this->project->id}/contacts", [
        'name' => 'Injected', 'role' => 'other', 'company_id' => $other->id,
    ])->assertRedirect();

    expect(ProjectContact::withoutGlobalScopes()->firstOrFail()->company_id)->toBe($this->company->id);
});

it('lets an admin edit a client contact', function (): void {
    $c = ProjectContact::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $this->project->id,
        'name' => 'Old', 'role' => 'other',
    ]);

    $this->put("/projects/{$this->project->id}/contacts/{$c->id}", [
        'name' => 'New Name', 'role' => 'project_manager', 'phone' => null, 'email' => null, 'notes' => null,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $c->refresh();
    expect($c->name)->toBe('New Name')->and($c->role->value)->toBe('project_manager');
});

it('lets an admin delete a client contact', function (): void {
    $c = ProjectContact::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $this->project->id,
    ]);

    $this->delete("/projects/{$this->project->id}/contacts/{$c->id}")
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(ProjectContact::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects an invalid email and a bad role', function (): void {
    $this->post("/projects/{$this->project->id}/contacts", [
        'name' => 'Bad', 'role' => 'not_a_role', 'email' => 'not-an-email',
    ])->assertSessionHasErrors(['role', 'email']);

    $this->post("/projects/{$this->project->id}/contacts", [
        'role' => 'other',
    ])->assertSessionHasErrors('name');
});

it('404s a contact reached through the wrong project', function (): void {
    $otherProject = Project::factory()->forCompany($this->company)->create();
    $c = ProjectContact::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $this->project->id,
    ]);

    // The contact belongs to $this->project, not $otherProject.
    $this->delete("/projects/{$otherProject->id}/contacts/{$c->id}")->assertNotFound();
});

it('cannot touch another company project contact', function (): void {
    $other = Company::factory()->create();
    $otherProject = Project::factory()->forCompany($other)->create();
    $c = ProjectContact::factory()->create([
        'company_id' => $other->id, 'project_id' => $otherProject->id,
    ]);

    // The project is scoped away → 404 (never a 403 that leaks existence).
    $this->put("/projects/{$otherProject->id}/contacts/{$c->id}", [
        'name' => 'Hack', 'role' => 'other',
    ])->assertNotFound();

    $this->delete("/projects/{$otherProject->id}/contacts/{$c->id}")->assertNotFound();
});

it('forbids a user without projects.edit', function (): void {
    $manager = User::factory()->forCompany($this->company)->create(); // role: manager, no grants
    $this->actingAs($manager);

    $this->post("/projects/{$this->project->id}/contacts", [
        'name' => 'Nope', 'role' => 'other',
    ])->assertForbidden();
});

it('ships the project contacts to the detail page', function (): void {
    ProjectContact::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $this->project->id,
        'name' => 'Shown Contact', 'role' => 'supervisor',
    ]);

    $this->get("/projects/{$this->project->id}")
        ->assertInertia(fn ($page) => $page
            ->has('projectContacts', 1)
            ->where('projectContacts.0.name', 'Shown Contact')
            ->where('projectContacts.0.role', 'supervisor'));
});
