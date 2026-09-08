<?php

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Measurement;
use App\Models\Project;
use App\Models\ReportRemark;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->adminA = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
});

it('lists only the active company projects (company-owned)', function (): void {
    Project::factory()->count(2)->forCompany($this->companyA)->create();
    Project::factory()->count(3)->forCompany($this->companyB)->create();

    $this->actingAs($this->adminA)->get('/projects')
        ->assertInertia(fn (Assert $page) => $page->component('Projects/Index')->has('projects.data', 2));
});

it('cannot open another company project (404)', function (): void {
    $foreign = Project::factory()->forCompany($this->companyB)->create();

    $this->actingAs($this->adminA)->get("/projects/{$foreign->id}")->assertNotFound();
});

it('creates a project with a generated code in the active company', function (): void {
    $client = Client::factory()->create();

    $this->actingAs($this->adminA)->post('/projects', [
        'name' => 'Obra Castellana',
        'client_id' => $client->id,
        'status' => 'active',
        'priority' => 'high',
        'vat_rate' => 'general',
    ])->assertRedirect();

    $project = Project::withoutGlobalScopes()->where('name', 'Obra Castellana')->firstOrFail();

    expect($project->company_id)->toBe($this->companyA->id)
        // Change 6: auto-generated codes now use the legacy Verto6### format.
        ->and($project->code)->toStartWith('Verto6')
        ->and($project->vat_rate->value)->toBe('general');
});

it('ignores an injected company_id on create', function (): void {
    $this->actingAs($this->adminA)->post('/projects', [
        'name' => 'Sneaky',
        'company_id' => $this->companyB->id,
        'status' => 'active',
        'priority' => 'medium',
    ])->assertRedirect();

    expect(Project::withoutGlobalScopes()->where('name', 'Sneaky')->value('company_id'))
        ->toBe($this->companyA->id);
});

it('moves a project across kanban columns via status update', function (): void {
    $project = Project::factory()->forCompany($this->companyA)->create(['status' => 'active']);

    $this->actingAs($this->adminA)->put("/projects/{$project->id}/status", ['status' => 'completed'])
        ->assertRedirect();

    expect($project->fresh()->status->value)->toBe('completed');
});

it('assigns and removes an own-company worker', function (): void {
    $project = Project::factory()->forCompany($this->companyA)->create();
    $employee = Employee::factory()->forCompany($this->companyA)->create();

    $this->actingAs($this->adminA)->post("/projects/{$project->id}/workers", [
        'employee_id' => $employee->id, 'project_rate' => 18.5,
    ])->assertRedirect();

    $rate = $project->employeeRates()->firstOrFail();
    expect($rate->getAttribute('project_rate'))->toBe('18.5');

    $this->delete("/projects/{$project->id}/workers/{$rate->id}")->assertRedirect();
    expect($project->employeeRates()->count())->toBe(0);
});

it('keeps project notes immutable once saved', function (): void {
    $project = Project::factory()->forCompany($this->companyA)->create();

    $this->actingAs($this->adminA)->post("/projects/{$project->id}/remarks", [
        'type' => 'internal', 'body' => 'Nota inicial',
    ])->assertRedirect();

    $remark = ReportRemark::query()->where('project_id', $project->id)->firstOrFail();

    // The model blocks both update and delete at the model layer
    expect(fn () => $remark->update(['body' => 'tampered']))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $remark->delete())->toThrow(RuntimeException::class);
});

it('soft-hard rule: projects are hard-deletable (not in the soft-delete list)', function (): void {
    $project = Project::factory()->forCompany($this->companyA)->create();

    $this->actingAs($this->adminA)->delete("/projects/{$project->id}")->assertRedirect();

    expect(Project::withoutGlobalScopes()->find($project->id))->toBeNull();
});

it('denies project creation without permission', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($user)->post('/projects', ['name' => 'X', 'status' => 'active', 'priority' => 'low'])
        ->assertForbidden();
});

it('shows the project attendance tab with records and a period summary', function (): void {
    $project = Project::factory()->forCompany($this->companyA)->create();
    $employee = Employee::factory()->forCompany($this->companyA)->create([
        'wage_type' => 'daily', 'daily_wage' => '80', 'designation' => 'Maestro',
    ]);
    Attendance::factory()->create([
        'company_id' => $this->companyA->id, 'employee_id' => $employee->id, 'project_id' => $project->id,
        'date' => now()->format('Y-m-01'), 'status' => 'present', 'day_type' => 'full',
        'hours_worked' => '8', 'total_amount' => '80', 'wage_rate_snapshot' => '80',
    ]);

    $this->actingAs($this->adminA)->get("/projects/{$project->id}?att_month=".now()->format('Y-m'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projectAttendance.records', 1)
            ->where('projectAttendance.summary.workers', 1)
            ->where('projectAttendance.summary.days', 1)
            ->where('projectAttendance.summary.hours', 8)
            ->where('projectAttendance.summary.labour_cost', 80));
});

it('no longer ships the measurements-tab payload on the project page, data intact', function (): void {
    // The Measurements tab was removed from the project detail page (2026-09).
    // The page must no longer ship its payload, while the measurement DATA and
    // the standalone /measurements workflow stay fully intact.
    $project = Project::factory()->forCompany($this->companyA)->create(['billing_type' => 'per_meter']);
    $employee = Employee::factory()->forCompany($this->companyA)->create();

    $m = new Measurement([
        'project_id' => $project->id, 'employee_id' => $employee->id,
        'date' => '2026-05-10', 'quantity' => '850', 'unit' => 'm²', 'measurement_type' => 'area',
    ]);
    $m->company_id = $this->companyA->id;
    $m->approved = true;
    $m->save();

    $this->actingAs($this->adminA)->get("/projects/{$project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('projectMeasurements')
            ->missing('measurementTypes')
            ->missing('canManageMeasurements'));

    // Data untouched, and still reachable on the standalone screen.
    expect(Measurement::where('project_id', $project->id)->count())->toBe(1);
    $this->actingAs($this->adminA)->get('/measurements')->assertOk();
});
