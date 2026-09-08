<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\TaskProgress;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
    $this->project = Project::factory()->forCompany($this->companyA)->create();
    $this->task = ProductionTask::factory()->create([
        'company_id' => $this->companyA->id, 'project_id' => $this->project->id,
        'planned_quantity' => 300, 'completed_quantity' => 0, 'unit' => 'm2',
    ]);
    $this->date = now()->subDay()->toDateString();
});

/** Create a worked attendance row for an employee on the project on $this->date. */
function present(int $companyId, int $projectId, string $date): Employee
{
    $emp = Employee::factory()->create(['company_id' => $companyId]);
    Attendance::factory()->create([
        'company_id' => $companyId, 'employee_id' => $emp->id,
        'project_id' => $projectId, 'date' => $date, 'status' => 'present',
    ]);

    return $emp;
}

it('splits a logged quantity equally and rolls it up into completed_quantity', function (): void {
    $a = present($this->companyA->id, $this->project->id, $this->date);
    $b = present($this->companyA->id, $this->project->id, $this->date);

    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 100, 'employee_ids' => [$a->id, $b->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $rows = TaskProgress::withoutGlobalScopes()->where('production_task_id', $this->task->id)->get();
    expect($rows)->toHaveCount(2)
        ->and((float) $rows->sum(fn (TaskProgress $r) => (float) $r->quantity))->toBe(100.0)
        ->and($rows->pluck('batch_id')->unique())->toHaveCount(1);

    // completed_quantity is recomputed to the batch total.
    expect((float) $this->task->refresh()->completed_quantity)->toBe(100.0);
});

it('gives the rounding remainder to the last worker so the sum is exact', function (): void {
    $a = present($this->companyA->id, $this->project->id, $this->date);
    $b = present($this->companyA->id, $this->project->id, $this->date);
    $c = present($this->companyA->id, $this->project->id, $this->date);

    // 100 / 3 = 33.33 × 2 + 33.34 = 100.00
    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 100, 'employee_ids' => [$a->id, $b->id, $c->id],
    ])->assertRedirect();

    $sum = (float) TaskProgress::withoutGlobalScopes()->where('production_task_id', $this->task->id)->sum('quantity');
    expect($sum)->toBe(100.0);
});

it('refuses to credit a worker not present on the project that day', function (): void {
    $present = present($this->companyA->id, $this->project->id, $this->date);
    $absent = Employee::factory()->create(['company_id' => $this->companyA->id]); // no attendance

    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 50, 'employee_ids' => [$present->id, $absent->id],
    ])->assertSessionHasErrors('employee_ids');

    expect(TaskProgress::withoutGlobalScopes()->count())->toBe(0);
});

it('stores and serves a proof photo, gated and audited', function (): void {
    Storage::fake('local');
    $a = present($this->companyA->id, $this->project->id, $this->date);

    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 40, 'employee_ids' => [$a->id],
        'photo' => UploadedFile::fake()->image('site.jpg'),
    ])->assertRedirect();

    $row = TaskProgress::withoutGlobalScopes()->firstWhere('production_task_id', $this->task->id);
    expect($row->photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($row->photo_path);

    $this->actingAs($this->admin)->get("/task-progress/{$row->id}/photo")->assertOk();
    $this->assertDatabaseHas('audit_logs', ['action' => 'viewed', 'model_type' => $row->getMorphClass(), 'model_id' => (string) $row->id]);
});

it('deletes a whole batch, removes its photo and recomputes the total', function (): void {
    Storage::fake('local');
    $a = present($this->companyA->id, $this->project->id, $this->date);
    $b = present($this->companyA->id, $this->project->id, $this->date);

    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 80, 'employee_ids' => [$a->id, $b->id],
        'photo' => UploadedFile::fake()->image('site.jpg'),
    ])->assertRedirect();

    $batch = TaskProgress::withoutGlobalScopes()->first();
    $photoPath = $batch->photo_path;
    expect((float) $this->task->refresh()->completed_quantity)->toBe(80.0);

    $this->delete("/projects/{$this->project->id}/tasks/{$this->task->id}/progress/{$batch->batch_id}")->assertRedirect();

    expect(TaskProgress::withoutGlobalScopes()->count())->toBe(0)
        ->and((float) $this->task->refresh()->completed_quantity)->toBe(0.0);
    Storage::disk('local')->assertMissing($photoPath);
});

it('purges progress photos from disk when the whole task is deleted', function (): void {
    Storage::fake('local');
    $a = present($this->companyA->id, $this->project->id, $this->date);

    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 30, 'employee_ids' => [$a->id],
        'photo' => UploadedFile::fake()->image('site.jpg'),
    ])->assertRedirect();

    $photoPath = TaskProgress::withoutGlobalScopes()->first()->photo_path;
    Storage::disk('local')->assertExists($photoPath);

    // Deleting the task cascade-removes the progress rows; the photo file must
    // not orphan.
    $this->actingAs($this->admin)->delete("/projects/{$this->project->id}/tasks/{$this->task->id}")->assertRedirect();

    expect(ProductionTask::withoutGlobalScopes()->whereKey($this->task->id)->exists())->toBeFalse()
        ->and(TaskProgress::withoutGlobalScopes()->count())->toBe(0);
    Storage::disk('local')->assertMissing($photoPath);
});

it('audits the photo download with a description, not stray old-values', function (): void {
    Storage::fake('local');
    $a = present($this->companyA->id, $this->project->id, $this->date);
    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 10, 'employee_ids' => [$a->id],
        'photo' => UploadedFile::fake()->image('x.jpg'),
    ])->assertRedirect();
    $row = TaskProgress::withoutGlobalScopes()->first();

    $this->actingAs($this->admin)->get("/task-progress/{$row->id}/photo")->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'viewed', 'model_type' => $row->getMorphClass(), 'model_id' => (string) $row->id,
        'description' => 'Task progress photo', 'old_values' => null,
    ]);
});

it('lists the workers present on the project for a date', function (): void {
    $a = present($this->companyA->id, $this->project->id, $this->date);
    present($this->companyA->id, $this->project->id, now()->subDays(5)->toDateString()); // other day

    $this->actingAs($this->admin)
        ->getJson("/projects/{$this->project->id}/present-workers?date={$this->date}")
        ->assertOk()
        ->assertJsonCount(1, 'workers')
        ->assertJsonPath('workers.0.id', $a->id);
});

it('ships the daily-production history in the task payload', function (): void {
    $a = present($this->companyA->id, $this->project->id, $this->date);
    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 25, 'employee_ids' => [$a->id],
    ])->assertRedirect();

    $this->actingAs($this->admin)->get("/projects/{$this->project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('projectTasks.tasks.0.batches', 1)
            ->where('projectTasks.tasks.0.batches.0.quantity', fn ($v) => (float) $v === 25.0)
            ->where('projectTasks.tasks.0.completed_quantity', fn ($v) => (float) $v === 25.0));
});

it('404s when logging against a task of another project', function (): void {
    $otherProject = Project::factory()->forCompany($this->companyA)->create();
    $a = present($this->companyA->id, $otherProject->id, $this->date);

    // The task belongs to $this->project, not $otherProject → 404.
    $this->actingAs($this->admin)->post("/projects/{$otherProject->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 10, 'employee_ids' => [$a->id],
    ])->assertNotFound();
});

it('404s a cross-company task-progress photo download', function (): void {
    Storage::fake('local');
    $a = present($this->companyA->id, $this->project->id, $this->date);
    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 10, 'employee_ids' => [$a->id],
        'photo' => UploadedFile::fake()->image('x.jpg'),
    ])->assertRedirect();
    $row = TaskProgress::withoutGlobalScopes()->first();

    // A company B admin cannot reach company A's progress photo (scope → 404).
    $foreignAdmin = User::factory()->companyAdmin()->forCompany($this->companyB)->create();
    $this->actingAs($foreignAdmin)->get("/task-progress/{$row->id}/photo")->assertNotFound();
});

it('requires the edit permission to log work', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->companyA->id, 'module' => 'production_tasks',
        'can_view' => true, 'can_edit' => false,
    ]);
    $a = present($this->companyA->id, $this->project->id, $this->date);

    $this->actingAs($user)->post("/projects/{$this->project->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 10, 'employee_ids' => [$a->id],
    ])->assertForbidden();
});

it('credits a DEPLOYED worker for task progress (allowDeployed) — regression', function (): void {
    // The reported production bug: a worker deployed INTO the acting company
    // appears in the present-workers feed but the log 422'd because the request
    // rejected them as "not of this company". Their HOME is companyB; they are
    // deployed onto this (companyA) project, with worked attendance under the
    // HOST company — exactly the real Shizukani→Alovar case.
    $deployed = Employee::factory()->create(['company_id' => $this->companyB->id]);
    EmployeeDeployment::factory()->create([
        'home_company_id' => $this->companyB->id,
        'host_company_id' => $this->companyA->id,
        'employee_id' => $deployed->id,
        'project_id' => $this->project->id,
        'deployment_start' => now()->subDays(5)->toDateString(),
        'deployment_end' => now()->addDays(5)->toDateString(),
    ]);
    Attendance::factory()->create([
        'company_id' => $this->companyA->id, 'employee_id' => $deployed->id,
        'project_id' => $this->project->id, 'date' => $this->date, 'status' => 'present',
    ]);

    $this->actingAs($this->admin)->post("/projects/{$this->project->id}/tasks/{$this->task->id}/progress", [
        'date' => $this->date, 'quantity' => 50, 'employee_ids' => [$deployed->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect((float) $this->task->refresh()->completed_quantity)->toBe(50.0)
        ->and(TaskProgress::withoutGlobalScopes()->where('production_task_id', $this->task->id)->count())->toBe(1);
});
