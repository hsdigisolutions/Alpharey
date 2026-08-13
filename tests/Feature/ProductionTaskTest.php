<?php

use App\Models\Company;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\User;
use App\Models\UserModulePermission;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
    $this->project = Project::factory()->forCompany($this->companyA)->create();
});

it('bulk-adds production tasks scoped to the project and company', function (): void {
    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks", [
        'tasks' => [
            ['name' => 'Colocar azulejos', 'category' => 'civil', 'unit' => 'm2', 'unit_price' => 12, 'planned_quantity' => 500, 'weightage' => 60, 'status' => 'in_progress'],
            ['name' => 'Pintar muros', 'category' => 'finishing', 'unit' => 'm2', 'unit_price' => 8, 'planned_quantity' => 200, 'weightage' => 40, 'status' => 'open'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $tasks = ProductionTask::withoutGlobalScopes()->where('project_id', $this->project->id)->get();
    expect($tasks)->toHaveCount(2)
        ->and($tasks->every(fn (ProductionTask $t) => $t->company_id === $this->companyA->id))->toBeTrue()
        ->and((float) $tasks->firstWhere('name', 'Colocar azulejos')->planned_quantity)->toBe(500.0);
});

it('computes progress percent and the completion traffic light', function (): void {
    $green = ProductionTask::factory()->create(['company_id' => $this->companyA->id, 'project_id' => $this->project->id, 'planned_quantity' => 100, 'completed_quantity' => 95]);
    $amber = ProductionTask::factory()->create(['company_id' => $this->companyA->id, 'project_id' => $this->project->id, 'planned_quantity' => 100, 'completed_quantity' => 60]);
    $red = ProductionTask::factory()->create(['company_id' => $this->companyA->id, 'project_id' => $this->project->id, 'planned_quantity' => 100, 'completed_quantity' => 30]);

    expect($green->progressPercent())->toBe(95.0)->and($green->health())->toBe('ok')
        ->and($amber->health())->toBe('warn')
        ->and($red->health())->toBe('danger');

    // 0 planned never divides by zero.
    $zero = ProductionTask::factory()->create(['company_id' => $this->companyA->id, 'project_id' => $this->project->id, 'planned_quantity' => 0]);
    expect($zero->progressPercent())->toBe(0.0);
});

it('ships the tasks payload with a weighted overall progress on the project page', function (): void {
    // 500-planned/250-done @ 60% weight (50% done) + 200/200 @ 40% weight (100%)
    // → weighted 0.5*60 + 1.0*40 = 70%.
    ProductionTask::factory()->create(['company_id' => $this->companyA->id, 'project_id' => $this->project->id, 'planned_quantity' => 500, 'completed_quantity' => 250, 'weightage' => 60]);
    ProductionTask::factory()->create(['company_id' => $this->companyA->id, 'project_id' => $this->project->id, 'planned_quantity' => 200, 'completed_quantity' => 200, 'weightage' => 40]);

    $this->actingAs($this->admin)->get("/projects/{$this->project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('projectTasks.tasks', 2)
            ->where('projectTasks.overall_progress', fn ($v) => (float) $v === 70.0)
            ->where('projectTasks.weightage_sum', fn ($v) => (float) $v === 100.0));
});

it('updates and deletes a task; completed_quantity is not mass-assignable', function (): void {
    $task = ProductionTask::factory()->create(['company_id' => $this->companyA->id, 'project_id' => $this->project->id, 'completed_quantity' => 40]);

    $this->actingAs($this->admin)->put("/projects/{$this->project->id}/tasks/{$task->id}", [
        'name' => 'Renamed', 'category' => 'civil', 'planned_quantity' => 999, 'status' => 'done',
        'completed_quantity' => 9999, // injection — ignored
    ])->assertRedirect();

    $task->refresh();
    expect($task->name)->toBe('Renamed')
        ->and((float) $task->planned_quantity)->toBe(999.0)
        ->and((float) $task->completed_quantity)->toBe(40.0); // untouched

    $this->delete("/projects/{$this->project->id}/tasks/{$task->id}")->assertRedirect();
    expect(ProductionTask::withoutGlobalScopes()->whereKey($task->id)->exists())->toBeFalse();
});

it('cannot manage tasks on another company project (404)', function (): void {
    $foreignProject = Project::factory()->forCompany($this->companyB)->create();
    $foreignTask = ProductionTask::factory()->create(['company_id' => $this->companyB->id, 'project_id' => $foreignProject->id]);

    $this->actingAs($this->admin)->post("/projects/{$foreignProject->id}/tasks", [
        'tasks' => [['name' => 'X', 'category' => 'civil', 'planned_quantity' => 1, 'status' => 'open']],
    ])->assertNotFound();
    $this->put("/projects/{$foreignProject->id}/tasks/{$foreignTask->id}", [
        'name' => 'X', 'category' => 'civil', 'planned_quantity' => 1, 'status' => 'open',
    ])->assertNotFound();

    // A task from another project on MY project → 404 (ownership re-check).
    $this->delete("/projects/{$this->project->id}/tasks/{$foreignTask->id}")->assertNotFound();
});

it('requires the create permission', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->companyA->id, 'module' => 'production_tasks',
        'can_view' => true, 'can_create' => false,
    ]);

    $this->actingAs($user)->post("/projects/{$this->project->id}/tasks", [
        'tasks' => [['name' => 'X', 'category' => 'civil', 'planned_quantity' => 1, 'status' => 'open']],
    ])->assertForbidden();
});
