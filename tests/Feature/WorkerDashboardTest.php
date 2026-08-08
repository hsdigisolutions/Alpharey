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

it('counts present and absent days and sums hours and pay', function (): void {
    $month = '2026-05';

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
});

it('marks each calendar day with the right status', function (): void {
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
        // A day with no record is grey, never auto-marked absent.
        ->and($byDay[7]['status'])->toBe('none')
        // 3 May 2026 is a Sunday — grey.
        ->and($byDay[3]['status'])->toBe('none');
});

it('never counts a day with no record as an absence', function (): void {
    // The app must not accuse a worker of an absence they never reported.
    $data = $this->service->forMonth($this->employee, '2026-05');

    expect($data['absent'])->toBe(0)
        ->and($data['present'])->toBe(0);
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
