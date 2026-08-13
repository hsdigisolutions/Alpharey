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

    $this->project = Project::factory()->forCompany($this->company)->create([
        'billing_type' => 'hourly', 'client_hour_rate' => '20',
    ]);

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

// Spec acceptance test 7: designation client_rate DOES drive P&L income, while
// COST comes from the workers' own profile rates (worker_rate is reference-only).
it('computes daily P&L income from designation client rates and cost from profile rates', function (): void {
    $svc = app(AttendanceService::class);

    // 07 Aug: Maestro, 2× Peón, Electricista — all 8h on the project.
    $svc->create(['employee_id' => worker($this->maestro->id)->id, 'project_id' => $this->project->id, 'date' => '2026-08-07', 'mode' => 'hourly', 'day_type' => 'hourly', 'status' => 'present', 'hours_worked' => '8']);
    $svc->create(['employee_id' => worker($this->peon->id)->id, 'project_id' => $this->project->id, 'date' => '2026-08-07', 'mode' => 'hourly', 'day_type' => 'hourly', 'status' => 'present', 'hours_worked' => '8']);
    $svc->create(['employee_id' => worker($this->peon->id)->id, 'project_id' => $this->project->id, 'date' => '2026-08-07', 'mode' => 'hourly', 'day_type' => 'hourly', 'status' => 'present', 'hours_worked' => '8']);
    $svc->create(['employee_id' => worker($this->elec->id)->id, 'project_id' => $this->project->id, 'date' => '2026-08-07', 'mode' => 'hourly', 'day_type' => 'hourly', 'status' => 'present', 'hours_worked' => '8']);

    $pnl = app(ProfitabilityService::class)->dailyPnl($this->project->fresh());

    expect($pnl['days'])->toHaveCount(1);
    $day = $pnl['days'][0];

    // Income (client side) = 160 + 120 + 120 + 200 = 600.
    // Cost = each worker's PROFILE rate: 4 workers × 8h × 5 €/h = 160.
    // The designation worker_rate (15/10/18) is reference-only — never cost.
    expect($day['date'])->toBe('2026-08-07')
        ->and($day['workers_count'])->toBe(4)
        ->and((float) $day['hours'])->toBe(32.0)
        ->and((float) $day['income'])->toBe(600.0)
        ->and((float) $day['labour'])->toBe(160.0)
        ->and((float) $day['profit'])->toBe(440.0)
        ->and($day['workers'])->toHaveCount(4);

    // One electricista worker line: income 8h × 25 client = 200; cost 8h × 5
    // profile = 40; the displayed worker rate is the real snapshot (5), never
    // the reference worker_rate (18).
    $elecLine = collect($day['workers'])->firstWhere('income', 200.0);
    expect((float) $elecLine['cost'])->toBe(40.0)
        ->and((float) $elecLine['client_rate'])->toBe(25.0)
        ->and((float) $elecLine['worker_rate'])->toBe(5.0);

    // Total + KPI figures.
    expect((float) $pnl['totals']['income'])->toBe(600.0)
        ->and((float) $pnl['totals']['profit'])->toBe(440.0)
        ->and((float) $pnl['months'][0]['income'])->toBe(600.0);
});
