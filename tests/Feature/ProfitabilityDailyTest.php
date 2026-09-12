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

// Universal hours fix (2026-09): a FULL-DAY (jornada) worker whose pay is a fixed
// daily rate leaves hours_worked = 0, yet is present a full 08:00–17:00 day. Every
// hours surface derives 8 net hours from that span via displayHoursNet(); the P&L
// must too, so an hourly-billed project bills the client for the 8 hours (it used
// to read 0 h → €0). The fix is UNIVERSAL: it keys off the attendance day_type,
// not the project billing type, and never touches the stored pay column.
it('counts a full-day jornada worker as 8 net hours in daily P&L (not the raw 0)', function (): void {
    $svc = app(AttendanceService::class);

    // Daily-wage worker, no designation → income uses the project client_hour_rate.
    $emp = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '100', 'designation_id' => null,
    ]);

    $svc->create([
        'employee_id' => $emp->id, 'project_id' => $this->project->id, 'date' => '2026-09-10',
        'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
        'check_in' => '08:00', 'check_out' => '17:00',
    ]);

    $row = Attendance::withoutGlobalScopes()->firstOrFail();
    // The stored PAY field is untouched (full days are a fixed daily rate)…
    expect((float) $row->hours_worked)->toBe(0.0)
        // …but the DISPLAY/billing hours derive 8 from the 08:00–17:00 span
        // (9 h span − 1 h break, default 60 min).
        ->and($row->displayHoursNet(60))->toBe(8.0);

    $pnl = app(ProfitabilityService::class)->dailyPnl($this->project->fresh());
    $day = $pnl['days'][0];

    // Before the fix: 0 h → €0 income. Now: 8 net h × 20 €/h client = €160.
    // Cost stays the frozen daily rate (100) — the money side is unchanged.
    expect((float) $day['hours'])->toBe(8.0)
        ->and((float) $day['income'])->toBe(160.0)
        ->and((float) $day['labour'])->toBe(100.0)
        ->and((float) $day['profit'])->toBe(60.0);

    // Project-level P&L agrees (revenue folds the same 8 net hours).
    $summary = app(ProfitabilityService::class)->forProject($this->project->fresh());
    expect((float) $summary['hours'])->toBe(8.0)
        ->and((float) $summary['revenue'])->toBe(160.0);
});
