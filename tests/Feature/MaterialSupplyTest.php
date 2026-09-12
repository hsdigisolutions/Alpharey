<?php

use App\Enums\MaterialSupply;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\ProfitabilityService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Item 3 — the project-level material-supply label. Descriptive metadata only:
 * it is stored, shown, and validated, but never touches P&L / invoicing / payroll.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);
});

it('stores and ships the material-supply label', function (): void {
    $this->post('/projects', [
        'name' => 'Obra Materiales', 'status' => 'active', 'priority' => 'medium',
        'material_supply' => 'company',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = Project::withoutGlobalScopes()->where('name', 'Obra Materiales')->firstOrFail();
    expect($project->material_supply)->toBe(MaterialSupply::Company);

    $this->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $p) => $p->where('project.material_supply', 'company'));
});

it('allows a null material-supply (not specified)', function (): void {
    $this->post('/projects', [
        'name' => 'Sin materiales', 'status' => 'active', 'priority' => 'medium',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Project::withoutGlobalScopes()->where('name', 'Sin materiales')->firstOrFail()->material_supply)->toBeNull();
});

it('rejects an invalid material-supply value', function (): void {
    $this->post('/projects', [
        'name' => 'Bad', 'status' => 'active', 'priority' => 'medium',
        'material_supply' => 'nonsense',
    ])->assertSessionHasErrors('material_supply');
});

it('has ZERO effect on P&L numbers — metadata only', function (): void {
    $project = Project::factory()->forCompany($this->company)->create([
        'billing_type' => 'hourly', 'client_hour_rate' => '20', 'material_supply' => null,
    ]);
    Attendance::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => Employee::factory()->forCompany($this->company)->create()->id,
        'project_id' => $project->id, 'date' => '2026-06-01', 'status' => 'present',
        'day_type' => 'hourly', 'hours_worked' => '8', 'total_amount' => '100',
    ]);

    $before = app(ProfitabilityService::class)->forProject($project->fresh());
    $project->update(['material_supply' => 'company']); // set the label
    $after = app(ProfitabilityService::class)->forProject($project->fresh());

    expect($after['revenue'])->toBe($before['revenue'])
        ->and($after['cost'])->toBe($before['cost'])
        ->and($after['profit'])->toBe($before['profit']);
});
