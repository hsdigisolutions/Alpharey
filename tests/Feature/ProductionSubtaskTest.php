<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\User;
use App\Services\ProductionTasks\TaskProgressService;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
    $this->project = Project::factory()->forCompany($this->companyA)->create();
    $this->parent = ProductionTask::factory()->create([
        'company_id' => $this->companyA->id, 'project_id' => $this->project->id,
        'planned_quantity' => 0, 'completed_quantity' => 0, 'unit' => 'm2',
    ]);
});

function subtaskPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sub-tarea', 'category' => 'civil', 'unit' => 'm2',
        'unit_price' => 10, 'planned_quantity' => 100, 'weightage' => 50, 'status' => 'open',
    ], $overrides);
}

it('creates a sub-task with the correct parent_task_id', function (): void {
    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks", [
        'parent_task_id' => $this->parent->id,
        'tasks' => [subtaskPayload()],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $child = ProductionTask::withoutGlobalScopes()->where('name', 'Sub-tarea')->firstOrFail();
    expect($child->parent_task_id)->toBe($this->parent->id);
});

it('rolls a child planned + completed up into the parent', function (): void {
    ProductionTask::factory()->create([
        'company_id' => $this->companyA->id, 'project_id' => $this->project->id,
        'parent_task_id' => $this->parent->id, 'planned_quantity' => 100, 'completed_quantity' => 40,
    ]);
    // Adding another sub-task through the endpoint triggers the parent recompute.
    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks", [
        'parent_task_id' => $this->parent->id,
        'tasks' => [subtaskPayload(['planned_quantity' => 60])],
    ])->assertRedirect();

    $this->parent->refresh();
    expect((float) $this->parent->planned_quantity)->toBe(160.0)
        ->and((float) $this->parent->completed_quantity)->toBe(40.0);
});

it('updates parent progress when a child logs work', function (): void {
    $child = ProductionTask::factory()->create([
        'company_id' => $this->companyA->id, 'project_id' => $this->project->id,
        'parent_task_id' => $this->parent->id, 'planned_quantity' => 200, 'completed_quantity' => 0, 'unit' => 'm2',
    ]);
    app(TaskProgressService::class)->recomputeParent($this->parent);

    $date = now()->subDay()->toDateString();
    $emp = Employee::factory()->create(['company_id' => $this->companyA->id]);
    Attendance::factory()->create(['company_id' => $this->companyA->id, 'employee_id' => $emp->id, 'project_id' => $this->project->id, 'date' => $date, 'status' => 'present']);

    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks/{$child->id}/progress", [
        'date' => $date, 'quantity' => 100, 'employee_ids' => [$emp->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->parent->refresh();
    expect((float) $this->parent->completed_quantity)->toBe(100.0)
        ->and($this->parent->progressPercent())->toBe(50.0); // 100 / 200
});

it('refuses to create a sub-task of a sub-task (max one level)', function (): void {
    $child = ProductionTask::factory()->create([
        'company_id' => $this->companyA->id, 'project_id' => $this->project->id,
        'parent_task_id' => $this->parent->id,
    ]);

    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks", [
        'parent_task_id' => $child->id,
        'tasks' => [subtaskPayload()],
    ])->assertStatus(422);
});

it('deletes all sub-tasks when the parent is deleted', function (): void {
    ProductionTask::factory()->count(3)->create([
        'company_id' => $this->companyA->id, 'project_id' => $this->project->id, 'parent_task_id' => $this->parent->id,
    ]);

    $this->actingAs($this->admin)->delete("/projects/{$this->project->id}/tasks/{$this->parent->id}")->assertRedirect();

    expect(ProductionTask::withoutGlobalScopes()->where('parent_task_id', $this->parent->id)->count())->toBe(0)
        ->and(ProductionTask::withoutGlobalScopes()->find($this->parent->id))->toBeNull();
});

it('cannot parent a sub-task under another company task', function (): void {
    $foreignProject = Project::factory()->forCompany($this->companyB)->create();
    $foreignParent = ProductionTask::factory()->create(['company_id' => $this->companyB->id, 'project_id' => $foreignProject->id]);

    // The parent does not belong to $this->project → 404.
    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks", [
        'parent_task_id' => $foreignParent->id,
        'tasks' => [subtaskPayload()],
    ])->assertNotFound();

    expect(ProductionTask::withoutGlobalScopes()->where('name', 'Sub-tarea')->exists())->toBeFalse();
});
