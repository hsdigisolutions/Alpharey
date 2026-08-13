<?php

use App\Models\Company;
use App\Models\Project;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\UserModulePermission;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
    $this->project = Project::factory()->forCompany($this->companyA)->create();
});

it('creates a template scoped to the active company', function (): void {
    $this->actingAs($this->admin)->post('/task-templates', [
        'name' => 'Colocar azulejos', 'category' => 'civil', 'unit' => 'm2',
        'unit_price' => 12, 'planned_quantity' => 500, 'weightage' => 20, 'active' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $template = TaskTemplate::withoutGlobalScopes()->firstWhere('name', 'Colocar azulejos');
    expect($template)->not->toBeNull()
        ->and($template->company_id)->toBe($this->companyA->id)
        ->and((float) $template->unit_price)->toBe(12.0);
});

it('updates and deletes a template', function (): void {
    $template = TaskTemplate::factory()->create(['company_id' => $this->companyA->id, 'name' => 'Old']);

    $this->actingAs($this->admin)->put("/task-templates/{$template->id}", [
        'name' => 'New', 'category' => 'finishing', 'planned_quantity' => 300, 'active' => false,
    ])->assertRedirect();

    $template->refresh();
    expect($template->name)->toBe('New')->and($template->active)->toBeFalse();

    $this->delete("/task-templates/{$template->id}")->assertRedirect();
    expect(TaskTemplate::withoutGlobalScopes()->whereKey($template->id)->exists())->toBeFalse();
});

it('ships only the active templates of the company on the project page', function (): void {
    TaskTemplate::factory()->create(['company_id' => $this->companyA->id, 'name' => 'Active one']);
    TaskTemplate::factory()->inactive()->create(['company_id' => $this->companyA->id, 'name' => 'Retired']);
    TaskTemplate::factory()->create(['company_id' => $this->companyB->id, 'name' => 'Foreign']);

    $this->actingAs($this->admin)->get("/projects/{$this->project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('taskTemplates', 1)
            ->where('taskTemplates.0.name', 'Active one'));
});

it('404s when editing another company template', function (): void {
    $foreign = TaskTemplate::factory()->create(['company_id' => $this->companyB->id]);

    $this->actingAs($this->admin)->put("/task-templates/{$foreign->id}", [
        'name' => 'X', 'category' => 'civil',
    ])->assertNotFound();

    $this->delete("/task-templates/{$foreign->id}")->assertNotFound();
});

it('requires the create permission', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->companyA->id, 'module' => 'production_tasks',
        'can_view' => true, 'can_create' => false,
    ]);

    $this->actingAs($user)->post('/task-templates', [
        'name' => 'X', 'category' => 'civil',
    ])->assertForbidden();
});
