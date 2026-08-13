<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeWageRate;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Payroll\PayrollService;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);

    // A dehadi worker carrying all three rate bases at once.
    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '80', 'wage_rate' => '10', 'per_meter_rate' => '5',
    ]);

    $this->svc = app(AttendanceService::class);
});

function logDay(int $employeeId, string $date, string $dayType, array $extra = []): Attendance
{
    return app(AttendanceService::class)->create(array_merge([
        'employee_id' => $employeeId,
        'date' => $date,
        'mode' => $dayType === 'hourly' ? 'hourly' : 'project_based',
        'day_type' => $dayType,
        'status' => 'present',
    ], $extra));
}

// ── Per-day-type calculation ────────────────────────────────────────────────

it('pays a full day at the daily rate', function (): void {
    $row = logDay($this->employee->id, '2026-07-01', 'full');
    expect((float) $row->total_amount)->toBe(80.0);
});

it('pays a half day at half the daily rate', function (): void {
    $row = logDay($this->employee->id, '2026-07-02', 'half');
    expect((float) $row->total_amount)->toBe(40.0);
});

it('pays an hourly day at hours × hourly rate', function (): void {
    $row = logDay($this->employee->id, '2026-07-03', 'hourly', ['hours_worked' => 8]);
    expect((float) $row->total_amount)->toBe(80.0); // 8 × 10
});

it('pays a per-meter day at quantity × per-meter rate', function (): void {
    $row = logDay($this->employee->id, '2026-07-04', 'per_meter', ['quantity' => 30]);
    expect((float) $row->total_amount)->toBe(150.0); // 30 × 5
});

it('prices a partial day from the hourly rate when the worker HAS one', function (): void {
    // Review Fix 4B: an explicit hourly rate wins over the daily ÷ 8 fallback.
    $both = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '80', 'wage_rate' => '12',
    ]);

    $row = logDay($both->id, '2026-07-07', 'hourly', ['hours_worked' => 2]);

    expect((float) $row->hourly_rate_snapshot)->toBe(12.0)
        ->and((float) $row->total_amount)->toBe(24.0); // 12 × 2, not 80÷8×2
});

it('prices a partial day at daily ÷ 8 × hours when the worker has no hourly rate', function (): void {
    // A pure dehadi worker: daily 80, NO hourly rate. A 3-hour partial day must
    // price at 80 ÷ 8 × 3 = 30 €, never 0 € (spec C3/C4 fallback).
    $daily = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '80', 'wage_rate' => null,
    ]);

    $row = logDay($daily->id, '2026-07-06', 'hourly', ['hours_worked' => 3]);

    expect((float) $row->hourly_rate_snapshot)->toBe(10.0) // 80 ÷ 8
        ->and((float) $row->total_amount)->toBe(30.0);
});

// ── Never trust a client-sent total ─────────────────────────────────────────

it('always computes the total server-side, ignoring a posted total', function (): void {
    $e = $this->employee;
    $this->post('/attendance', [
        'employee_id' => $e->id,
        'date' => '2026-07-05',
        'mode' => 'project_based',
        'day_type' => 'full',
        'status' => 'present',
        'total_amount' => 9999, // ignored (no manual_wage_override)
    ])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $e->id)->where('date', '2026-07-05')->firstOrFail();
    expect((float) $row->total_amount)->toBe(80.0);
});

// ── Wage history governs the daily rate by date ─────────────────────────────

it('prices a full day from the wage-history rate in force on that date', function (): void {
    $e = $this->employee;

    // History: 80/day until Jul 10, then 100/day.
    $r1 = new EmployeeWageRate(['wage_type' => 'daily', 'rate' => '80', 'effective_from' => '2026-06-01', 'is_default' => false]);
    $r1->effective_to = '2026-07-10';
    $r1->employee_id = $e->id;
    $r1->company_id = $e->company_id;
    $r1->saveQuietly();
    $r2 = new EmployeeWageRate(['wage_type' => 'daily', 'rate' => '100', 'effective_from' => '2026-07-11', 'is_default' => true]);
    $r2->effective_to = null;
    $r2->employee_id = $e->id;
    $r2->company_id = $e->company_id;
    $r2->saveQuietly();

    $before = logDay($e->id, '2026-07-05', 'full');
    $after = logDay($e->id, '2026-07-15', 'full');

    expect((float) $before->total_amount)->toBe(80.0)
        ->and((float) $after->total_amount)->toBe(100.0);
});

// ── Payroll day-type breakdown ──────────────────────────────────────────────

it('breaks a mixed month into a day-type summary that reconciles to gross', function (): void {
    $e = $this->employee;

    // 3 full (80), 2 half (40), 1 hourly day of 8h × 10 = 80.
    foreach (['2026-07-01', '2026-07-02', '2026-07-03'] as $d) {
        logDay($e->id, $d, 'full');
    }
    foreach (['2026-07-06', '2026-07-07'] as $d) {
        logDay($e->id, $d, 'half');
    }
    logDay($e->id, '2026-07-10', 'hourly', ['hours_worked' => 8]);

    app(PayrollService::class)->calculateMonth($this->company->id, '2026-07');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $e->id)->firstOrFail();

    $summary = collect($payroll->day_type_summary);

    // 3×80 + 2×40 + 80 = 240 + 80 + 80 = 400
    expect((float) $payroll->gross_pay)->toBe(400.0)
        ->and($summary->firstWhere('type', 'full'))->not->toBeNull()
        ->and((float) $summary->firstWhere('type', 'full')['amount'])->toBe(240.0)
        ->and((float) $summary->firstWhere('type', 'full')['units'])->toBe(3.0)
        ->and((float) $summary->firstWhere('type', 'half')['amount'])->toBe(80.0)
        ->and((float) $summary->firstWhere('type', 'hourly')['amount'])->toBe(80.0);
});

// ── Tenancy ─────────────────────────────────────────────────────────────────

it('rejects a per-meter day for another company employee', function (): void {
    $other = Company::factory()->create();
    $foreign = Employee::factory()->forCompany($other)->create(['wage_type' => 'daily', 'per_meter_rate' => '5']);

    $this->post('/attendance', [
        'employee_id' => $foreign->id,
        'date' => '2026-07-01',
        'mode' => 'project_based',
        'day_type' => 'per_meter',
        'quantity' => 10,
        'status' => 'present',
    ])->assertSessionHasErrors('employee_id');

    expect(Attendance::withoutGlobalScopes()->where('employee_id', $foreign->id)->count())->toBe(0);
});
