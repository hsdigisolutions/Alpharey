<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
});

it('re-prices a worked full day left at 0 from the current daily rate', function (): void {
    // The stale/broken shape the day-type back-fill left behind.
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '50',
    ]);
    $row = Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => '2026-08-03', 'status' => 'present', 'day_type' => 'full',
        'total_amount' => '0', 'wage_rate_snapshot' => '80', 'manual_wage_override' => false,
    ]);

    $this->artisan('attendance:reprice-zero')->assertSuccessful();

    // Re-priced from the CURRENT daily rate (50), not the stale 80.
    expect((float) $row->fresh()->total_amount)->toBe(50.0)
        ->and((float) $row->fresh()->wage_rate_snapshot)->toBe(50.0);
});

it('never touches a correctly-priced row or a manual override', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '50',
    ]);
    // Correctly priced (total ≠ 0) — left alone.
    $ok = Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => '2026-08-04', 'status' => 'present', 'day_type' => 'full', 'total_amount' => '80',
    ]);
    // A deliberate 0 via manual override — left alone.
    $override = Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => '2026-08-05', 'status' => 'present', 'day_type' => 'full',
        'total_amount' => '0', 'manual_wage_override' => true,
    ]);

    $this->artisan('attendance:reprice-zero')->assertSuccessful();

    expect((float) $ok->fresh()->total_amount)->toBe(80.0)
        ->and((float) $override->fresh()->total_amount)->toBe(0.0);
});

it('dry-run reports but writes nothing', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create(['wage_type' => 'daily', 'daily_wage' => '50']);
    $row = Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => '2026-08-03', 'status' => 'present', 'day_type' => 'full', 'total_amount' => '0',
    ]);

    $this->artisan('attendance:reprice-zero', ['--dry-run' => true])->assertSuccessful();

    expect((float) $row->fresh()->total_amount)->toBe(0.0);
});
