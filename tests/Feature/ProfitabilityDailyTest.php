<?php

use App\Enums\ProjectRateType;
use App\Models\Company;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectDesignationRate;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Reports\ProfitabilityService;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);

    $this->maestro = Designation::factory()->create(['name' => 'Maestro']);
    $this->peon = Designation::factory()->create(['name' => 'Peón']);
    $this->elec = Designation::factory()->create(['name' => 'Electricista']);

    $this->project = Project::factory()->forCompany($this->company)->create(['client_hour_rate' => '20']);

    // Project rates per designation: [client, worker] €/h.
    foreach ([[$this->maestro, 20, 15], [$this->peon, 15, 10], [$this->elec, 25, 18]] as [$d, $c, $w]) {
        ProjectDesignationRate::factory()->create([
            'company_id' => $this->company->id, 'project_id' => $this->project->id,
            'designation_id' => $d->id, 'rate_type' => ProjectRateType::PerHour->value,
            'client_rate' => (string) $c, 'worker_rate' => (string) $w,
        ]);
    }
});

function worker(int $designationId): Employee
{
    return Employee::factory()->forCompany(test()->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '5', 'designation_id' => $designationId,
    ]);
}

it('computes the daily P&L per worker from client vs worker rates', function (): void {
    $svc = app(AttendanceService::class);

    // 07 Aug: Maestro, 2× Peón, Electricista — all 8h on the project.
    $svc->create(['employee_id' => worker($this->maestro->id)->id, 'project_id' => $this->project->id, 'date' => '2026-08-07', 'mode' => 'hourly', 'day_type' => 'hourly', 'status' => 'present', 'hours_worked' => '8']);
    $svc->create(['employee_id' => worker($this->peon->id)->id, 'project_id' => $this->project->id, 'date' => '2026-08-07', 'mode' => 'hourly', 'day_type' => 'hourly', 'status' => 'present', 'hours_worked' => '8']);
    $svc->create(['employee_id' => worker($this->peon->id)->id, 'project_id' => $this->project->id, 'date' => '2026-08-07', 'mode' => 'hourly', 'day_type' => 'hourly', 'status' => 'present', 'hours_worked' => '8']);
    $svc->create(['employee_id' => worker($this->elec->id)->id, 'project_id' => $this->project->id, 'date' => '2026-08-07', 'mode' => 'hourly', 'day_type' => 'hourly', 'status' => 'present', 'hours_worked' => '8']);

    $pnl = app(ProfitabilityService::class)->dailyPnl($this->project->fresh());

    expect($pnl['days'])->toHaveCount(1);
    $day = $pnl['days'][0];

    // Income = 160 + 120 + 120 + 200 = 600. Cost (worker) = 120 + 80 + 80 + 144 = 424.
    expect($day['date'])->toBe('2026-08-07')
        ->and($day['workers_count'])->toBe(4)
        ->and((float) $day['hours'])->toBe(32.0)
        ->and((float) $day['income'])->toBe(600.0)
        ->and((float) $day['labour'])->toBe(424.0)
        ->and((float) $day['profit'])->toBe(176.0)
        ->and($day['workers'])->toHaveCount(4);

    // One electricista worker line: 8h × 25 client = 200, × 18 worker = 144.
    $elecLine = collect($day['workers'])->firstWhere('income', 200.0);
    expect((float) $elecLine['cost'])->toBe(144.0)
        ->and((float) $elecLine['client_rate'])->toBe(25.0)
        ->and((float) $elecLine['worker_rate'])->toBe(18.0);

    // Total + KPI figures.
    expect((float) $pnl['totals']['income'])->toBe(600.0)
        ->and((float) $pnl['totals']['profit'])->toBe(176.0)
        ->and((float) $pnl['months'][0]['income'])->toBe(600.0);
});
