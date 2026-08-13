<?php

use App\Enums\ProjectRateType;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\ProjectDesignationRate;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Payroll\PayrollService;

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

// Salary-structure rule (2026-08-12): designation rates are CLIENT BILLING
// data. worker_rate is reference-only and must NEVER price a day — the worker
// is always paid their profile / wage-history rate, on every project.

it('never pays from a per-hour designation rate — the profile rate wins', function (): void {
    rate($this->project->id, $this->designation->id, ProjectRateType::PerHour->value, '15');

    $row = logProjectDay($this->employee->id, $this->project->id, 'hourly', ['hours_worked' => '8']);

    // 8h × 8 (profile hourly), NOT 8 × 15 (project worker_rate).
    expect((float) $row->total_amount)->toBe(64.0)
        ->and((float) $row->wage_rate_snapshot)->toBe(8.0);
});

it('never pays from a per-day designation rate — spec acceptance test 6', function (): void {
    // Profile daily 50; project designation worker_rate 80 → paid 50, not 80.
    rate($this->project->id, $this->designation->id, ProjectRateType::PerDay->value, '80');

    $row = logProjectDay($this->employee->id, $this->project->id, 'full');

    expect((float) $row->total_amount)->toBe(50.0)
        ->and((float) $row->wage_rate_snapshot)->toBe(50.0);

    // …and PAYROLL pays the same 50 (review Fix 1A: the whole chain, not just
    // the snapshot).
    app(PayrollService::class)->calculateMonth($this->company->id, '2026-07');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $this->employee->id)->firstOrFail();
    expect((float) $payroll->getAttribute('days_amount'))->toBe(50.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(50.0);
});

it('never pays from a per-meter designation rate — the profile per-meter rate wins', function (): void {
    rate($this->project->id, $this->designation->id, ProjectRateType::PerMeter->value, '4');

    $row = logProjectDay($this->employee->id, $this->project->id, 'per_meter', ['quantity' => '30']);

    expect((float) $row->total_amount)->toBe(90.0); // 30 × 3 (profile), not 30 × 4
});

it('pays the profile rate when the project has no rate for the designation', function (): void {
    // No ProjectDesignationRate row for this project/designation.
    $row = logProjectDay($this->employee->id, $this->project->id, 'hourly', ['hours_worked' => '8']);

    expect((float) $row->total_amount)->toBe(64.0); // 8 × 8 (profile hourly)
});

it('ignores designation-rate changes entirely — the frozen profile snapshot stands', function (): void {
    $projectRate = rate($this->project->id, $this->designation->id, ProjectRateType::PerHour->value, '15');

    $row = logProjectDay($this->employee->id, $this->project->id, 'hourly', ['hours_worked' => '8']);
    expect((float) $row->total_amount)->toBe(64.0); // profile 8/h — worker_rate ignored

    // Changing the project worker_rate never touches a logged day.
    $projectRate->update(['worker_rate' => '99']);

    expect((float) $row->fresh()->wage_rate_snapshot)->toBe(8.0)
        ->and((float) $row->fresh()->total_amount)->toBe(64.0);
});
