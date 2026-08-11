<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Services\Workers\WorkerDashboardService;

/**
 * The worker's own month figures + calendar (PWA dashboard). These are a
 * read-only view of the same attendance rows payroll reads, scoped to one
 * employee.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create();
    $this->service = app(WorkerDashboardService::class);
});

it('counts present and absent days and sums hours', function (): void {
    // Fix "today" to Thu 7 May so the past-weekday window is deterministic.
    $this->travelTo('2026-05-07 10:00');
    $this->employee->update(['joining_date' => '2026-05-04']);
    $month = '2026-05';

    // Mon 4 + Tue 5 present, Wed 6 a recorded absence. Today (Thu 7) is still
    // in progress, so there is no gap weekday to compute.
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'date' => "$month-04", 'status' => 'present', 'hours_worked' => '8', 'total_amount' => '160',
    ]);
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'date' => "$month-05", 'status' => 'present', 'hours_worked' => '7', 'total_amount' => '140',
    ]);
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'date' => "$month-06", 'status' => 'absent', 'hours_worked' => '0', 'total_amount' => '0',
    ]);

    $data = $this->service->forMonth($this->employee, $month);

    expect($data['present'])->toBe(2)
        ->and($data['absent'])->toBe(1)
        ->and($data['hours'])->toBe(15.0)
        // No 'earned' — workers never see money amounts (client rule 2026-08-08).
        ->and($data)->not->toHaveKey('earned')
        ->and($data['calendar'])->toHaveCount(31); // May has 31 days

    $this->travelBack();
});

it('marks each calendar day with the right status', function (): void {
    $this->travelTo('2026-05-04 10:00'); // today = Mon 4 May
    $this->employee->update(['joining_date' => '2026-05-04']);
    $month = '2026-05';

    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'date' => "$month-04", 'status' => 'present', 'hours_worked' => '8', 'total_amount' => '160',
    ]);

    $data = $this->service->forMonth($this->employee, $month);
    $byDay = collect($data['calendar'])->keyBy('day');

    // 4 May 2026 is a Monday (weekday 0) with a present record.
    expect($byDay[4]['status'])->toBe('present')
        ->and($byDay[4]['weekday'])->toBe(0)
        // 7 May is in the future (today is the 4th) — grey, not absent.
        ->and($byDay[7]['status'])->toBe('none')
        // 3 May 2026 is a Sunday — grey.
        ->and($byDay[3]['status'])->toBe('none');

    $this->travelBack();
});

it('counts a past weekday with no record as an absence', function (): void {
    // Fix 2 (client 2026-08-11): a weekday the worker was employed for, with no
    // attendance at all, IS an absence — shown live, before the nightly sweep.
    $this->travelTo('2026-05-08 10:00'); // today = Fri 8 May
    $this->employee->update(['joining_date' => '2026-05-04']);

    // Only Mon 4 present. Tue 5, Wed 6, Thu 7 are past weekdays with no record.
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'date' => '2026-05-04', 'status' => 'present', 'hours_worked' => '8', 'total_amount' => '160',
    ]);

    $data = $this->service->forMonth($this->employee, '2026-05');
    $byDay = collect($data['calendar'])->keyBy('day');

    expect($data['present'])->toBe(1)
        ->and($data['absent'])->toBe(3)
        // A computed (no-row) absence is flagged so the UI shades it lighter.
        ->and($byDay[7]['status'])->toBe('absent')
        ->and($byDay[7]['is_auto_generated'])->toBeTrue()
        // A day before the joining date is never the worker's absence.
        ->and($byDay[1]['status'])->toBe('none');

    $this->travelBack();
});

it('only ever sees the worker\'s own rows', function (): void {
    $month = '2026-05';
    $other = Employee::factory()->forCompany($this->company)->create();

    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $other->id,
        'date' => "$month-04", 'status' => 'present', 'hours_worked' => '8', 'total_amount' => '160',
    ]);

    $data = $this->service->forMonth($this->employee, $month);

    expect($data['present'])->toBe(0)->and($data)->not->toHaveKey('earned');
});

it('carries day type and project onto each calendar cell (no money)', function (): void {
    $month = '2026-05';
    $project = Project::factory()->forCompany($this->company)->create(['name' => 'Reforma Madrid']);

    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'project_id' => $project->id, 'date' => "$month-04", 'status' => 'present',
        'day_type' => 'full', 'hours_worked' => '8', 'total_amount' => '80',
    ]);

    $data = $this->service->forMonth($this->employee, $month);
    $cell = collect($data['calendar'])->firstWhere('day', 4);

    expect($cell['day_type'])->toBe('full')
        ->and((float) $cell['hours'])->toBe(8.0)
        ->and($cell)->not->toHaveKey('total')   // no money in the worker view
        ->and($cell['project'])->toBe('Reforma Madrid');
});
