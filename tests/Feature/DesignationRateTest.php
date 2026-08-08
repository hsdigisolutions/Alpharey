<?php

use App\Enums\ProjectRateType;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectDesignationRate;
use App\Models\User;
use App\Services\Attendance\AttendanceService;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);

    $this->designation = Designation::factory()->create();
    // A worker with a modest PROFILE rate — the project rate should beat it.
    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '8',
        'daily_wage' => '50', 'per_meter_rate' => '3',
        'designation_id' => $this->designation->id,
    ]);
    $this->project = Project::factory()->forCompany($this->company)->create();
});

function rate(int $projectId, int $designationId, string $type, string $worker, string $client = '30'): ProjectDesignationRate
{
    return ProjectDesignationRate::factory()->create([
        'company_id' => test()->company->id, 'project_id' => $projectId,
        'designation_id' => $designationId, 'rate_type' => $type,
        'worker_rate' => $worker, 'client_rate' => $client,
    ]);
}

function logProjectDay(int $employeeId, int $projectId, string $dayType, array $extra = []): Attendance
{
    return app(AttendanceService::class)->create(array_merge([
        'employee_id' => $employeeId, 'project_id' => $projectId,
        'date' => '2026-07-06', 'mode' => $dayType === 'hourly' ? 'hourly' : 'project_based',
        'day_type' => $dayType, 'status' => 'present',
    ], $extra));
}

it('lets an admin add and remove a project designation rate', function (): void {
    $this->post("/projects/{$this->project->id}/designation-rates", [
        'designation_id' => $this->designation->id,
        'client_rate' => '20', 'worker_rate' => '15', 'rate_type' => 'per_hour',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $rate = ProjectDesignationRate::withoutGlobalScopes()->firstOrFail();
    expect((float) $rate->client_rate)->toBe(20.0)
        ->and((float) $rate->worker_rate)->toBe(15.0)
        ->and($rate->company_id)->toBe($this->company->id);

    // Re-posting the same designation updates rather than duplicates.
    $this->post("/projects/{$this->project->id}/designation-rates", [
        'designation_id' => $this->designation->id,
        'client_rate' => '25', 'worker_rate' => '18', 'rate_type' => 'per_hour',
    ])->assertRedirect();
    expect(ProjectDesignationRate::withoutGlobalScopes()->count())->toBe(1)
        ->and((float) $rate->fresh()->client_rate)->toBe(25.0);

    $this->delete("/projects/{$this->project->id}/designation-rates/{$rate->id}")->assertRedirect();
    expect(ProjectDesignationRate::withoutGlobalScopes()->count())->toBe(0);
});

it('cannot delete a rate through another project', function (): void {
    $other = Project::factory()->forCompany($this->company)->create();
    $r = ProjectDesignationRate::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $this->project->id, 'designation_id' => $this->designation->id,
    ]);

    $this->delete("/projects/{$other->id}/designation-rates/{$r->id}")->assertNotFound();
});

it('prices a per-hour project designation rate over the profile rate', function (): void {
    rate($this->project->id, $this->designation->id, ProjectRateType::PerHour->value, '15');

    $row = logProjectDay($this->employee->id, $this->project->id, 'hourly', ['hours_worked' => '8']);

    // 8h × 15 (project rate) = 120, NOT 8 × 8 (profile).
    expect((float) $row->total_amount)->toBe(120.0)
        ->and((float) $row->wage_rate_snapshot)->toBe(15.0);
});

it('prices a per-day project designation rate as a full jornada', function (): void {
    rate($this->project->id, $this->designation->id, ProjectRateType::PerDay->value, '90');

    $row = logProjectDay($this->employee->id, $this->project->id, 'full');

    expect((float) $row->total_amount)->toBe(90.0); // the project daily rate
});

it('prices a per-meter project designation rate by quantity', function (): void {
    rate($this->project->id, $this->designation->id, ProjectRateType::PerMeter->value, '4');

    $row = logProjectDay($this->employee->id, $this->project->id, 'per_meter', ['quantity' => '30']);

    expect((float) $row->total_amount)->toBe(120.0); // 30 × 4
});

it('falls back to the profile rate when the project has no rate for the designation', function (): void {
    // No ProjectDesignationRate row for this project/designation.
    $row = logProjectDay($this->employee->id, $this->project->id, 'hourly', ['hours_worked' => '8']);

    expect((float) $row->total_amount)->toBe(64.0); // 8 × 8 (profile hourly)
});

it('freezes the project rate so a later rate change never rewrites the day', function (): void {
    $projectRate = rate($this->project->id, $this->designation->id, ProjectRateType::PerHour->value, '15');

    $row = logProjectDay($this->employee->id, $this->project->id, 'hourly', ['hours_worked' => '8']);
    expect((float) $row->total_amount)->toBe(120.0);

    // Raise the project rate — the already-logged day keeps its frozen 15.
    $projectRate->update(['worker_rate' => '99']);

    expect((float) $row->fresh()->wage_rate_snapshot)->toBe(15.0)
        ->and((float) $row->fresh()->total_amount)->toBe(120.0);
});
