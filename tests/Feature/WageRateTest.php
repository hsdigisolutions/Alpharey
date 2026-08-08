<?php

use App\Enums\PayrollStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeWageRate;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Employees\WageRateService;
use App\Services\Payroll\PayrollService;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
    $this->service = app(WageRateService::class);
});

/**
 * Build a rate row directly (no service), for controlled lookup scenarios.
 */
function makeRate(Employee $employee, string $type, string $rate, string $from, ?string $to): EmployeeWageRate
{
    $row = new EmployeeWageRate([
        'wage_type' => $type,
        'rate' => $rate,
        'effective_from' => $from,
        'is_default' => $to === null,
    ]);
    $row->effective_to = $to;
    $row->employee_id = $employee->id;
    $row->company_id = $employee->company_id;
    $row->saveQuietly();

    return $row;
}

// ── Rate lookup ─────────────────────────────────────────────────────────────

it('returns the correct rate for every date in the timeline', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily']);

    makeRate($e, 'daily', '50', '2024-06-01', '2024-12-31');
    makeRate($e, 'hourly', '60', '2025-01-01', '2025-02-28');
    makeRate($e, 'hourly', '70', '2025-03-01', null);

    expect((float) $this->service->rateForDate($e->id, '2024-08-15')->rate)->toBe(50.0)
        ->and((float) $this->service->rateForDate($e->id, '2024-12-31')->rate)->toBe(50.0) // boundary
        ->and((float) $this->service->rateForDate($e->id, '2025-01-01')->rate)->toBe(60.0) // boundary
        ->and((float) $this->service->rateForDate($e->id, '2025-02-28')->rate)->toBe(60.0)
        ->and((float) $this->service->rateForDate($e->id, '2025-03-01')->rate)->toBe(70.0)
        ->and((float) $this->service->rateForDate($e->id, '2030-01-01')->rate)->toBe(70.0); // open range
});

it('returns null when no rate covers the date', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily']);

    makeRate($e, 'daily', '50', '2024-06-01', null);

    // Before the earliest rate → nothing in force yet.
    expect($this->service->rateForDate($e->id, '2024-01-01'))->toBeNull();
});

it('returns null for an employee with no rate history', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily']);

    expect($this->service->rateForDate($e->id, '2026-07-01'))->toBeNull();
});

// ── Creating a rate ─────────────────────────────────────────────────────────

it('closes the previous rate and opens the new one', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily', 'daily_wage' => '50']);
    $this->service->seedFromEmployee($e);

    $this->service->createRate($e, [
        'effective_from' => '2026-07-11',
        'wage_type' => 'daily',
        'rate' => '70',
    ]);

    $rows = EmployeeWageRate::withoutGlobalScopes()->where('employee_id', $e->id)->orderBy('effective_from')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->effective_to->toDateString())->toBe('2026-07-10') // closed day before
        ->and($rows[1]->effective_to)->toBeNull()                          // new one open
        ->and((float) $rows[1]->rate)->toBe(70.0);
});

it('rejects a new rate dated on or before the current one', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily', 'daily_wage' => '50']);
    makeRate($e, 'daily', '50', '2026-07-01', null);

    $this->service->createRate($e, ['effective_from' => '2026-06-15', 'wage_type' => 'daily', 'rate' => '70']);
})->throws(ValidationException::class);

// ── Attendance snapshots follow the date ────────────────────────────────────

it('freezes the correct rate on each attendance day by date', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily', 'daily_wage' => '50']);

    makeRate($e, 'daily', '50', '2026-07-01', '2026-07-10');
    makeRate($e, 'daily', '70', '2026-07-11', null);

    $svc = app(AttendanceService::class);
    $before = $svc->create(['employee_id' => $e->id, 'date' => '2026-07-05', 'mode' => 'project_based', 'hours_worked' => 8, 'status' => 'present']);
    $after = $svc->create(['employee_id' => $e->id, 'date' => '2026-07-15', 'mode' => 'project_based', 'hours_worked' => 8, 'status' => 'present']);

    // 50/day → 6.25/h; 70/day → 8.75/h
    expect((float) $before->hourly_rate_snapshot)->toBe(6.25)
        ->and((float) $before->total_amount)->toBe(50.0)
        ->and((float) $after->hourly_rate_snapshot)->toBe(8.75)
        ->and((float) $after->total_amount)->toBe(70.0);
});

// ── Payroll multi-rate breakdown ────────────────────────────────────────────

it('breaks a two-rate month into periods that reconcile to gross', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily', 'daily_wage' => '50']);

    makeRate($e, 'daily', '50', '2026-07-01', '2026-07-10');
    makeRate($e, 'daily', '70', '2026-07-11', null);

    $svc = app(AttendanceService::class);
    foreach (['2026-07-03', '2026-07-07'] as $d) {
        $svc->create(['employee_id' => $e->id, 'date' => $d, 'mode' => 'project_based', 'hours_worked' => 8, 'status' => 'present']);
    }
    foreach (['2026-07-14', '2026-07-18', '2026-07-22'] as $d) {
        $svc->create(['employee_id' => $e->id, 'date' => $d, 'mode' => 'project_based', 'hours_worked' => 8, 'status' => 'present']);
    }

    app(PayrollService::class)->calculateMonth($this->companyA->id, '2026-07');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $e->id)->firstOrFail();

    $periods = $payroll->rate_periods;

    expect((float) $payroll->days_amount)->toBe(310.0) // 2×50 + 3×70
        ->and($periods)->toHaveCount(2)
        ->and((float) $periods[0]['rate'])->toBe(50.0)
        ->and((int) $periods[0]['days'])->toBe(2)
        ->and((float) $periods[0]['amount'])->toBe(100.0)
        ->and((float) $periods[1]['rate'])->toBe(70.0)
        ->and((int) $periods[1]['days'])->toBe(3)
        ->and((float) $periods[1]['amount'])->toBe(210.0);
});

it('handles three rate changes in one month', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily', 'daily_wage' => '50']);

    makeRate($e, 'daily', '50', '2026-07-01', '2026-07-09');
    makeRate($e, 'daily', '60', '2026-07-10', '2026-07-19');
    makeRate($e, 'daily', '70', '2026-07-20', null);

    $svc = app(AttendanceService::class);
    foreach (['2026-07-03', '2026-07-12', '2026-07-25'] as $d) {
        $svc->create(['employee_id' => $e->id, 'date' => $d, 'mode' => 'project_based', 'hours_worked' => 8, 'status' => 'present']);
    }

    app(PayrollService::class)->calculateMonth($this->companyA->id, '2026-07');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $e->id)->firstOrFail();

    expect($payroll->rate_periods)->toHaveCount(3)
        ->and((float) $payroll->days_amount)->toBe(180.0); // 50 + 60 + 70
});

// ── Paid records are never recalculated ─────────────────────────────────────

it('never reprices attendance in a paid month', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily', 'daily_wage' => '50']);
    makeRate($e, 'daily', '50', '2026-07-01', null);

    $svc = app(AttendanceService::class);
    $row = $svc->create(['employee_id' => $e->id, 'date' => '2026-07-05', 'mode' => 'project_based', 'hours_worked' => 8, 'status' => 'present']);

    // Pay the month.
    Payroll::withoutGlobalScopes()->create([
        'company_id' => $this->companyA->id,
        'employee_id' => $e->id,
        'month' => '2026-07',
        'status' => PayrollStatus::Paid->value,
    ]);

    // A back-dated raise that WOULD reprice July 5 if the month were open.
    $this->service->createRate($e, ['effective_from' => '2026-07-03', 'wage_type' => 'daily', 'rate' => '70']);

    $row->refresh();
    expect((float) $row->hourly_rate_snapshot)->toBe(6.25)  // still 50/day, untouched
        ->and((float) $row->total_amount)->toBe(50.0);
});

it('reprices unpaid attendance from a back-dated rate', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily', 'daily_wage' => '50']);
    makeRate($e, 'daily', '50', '2026-07-01', null);

    $svc = app(AttendanceService::class);
    $row = $svc->create(['employee_id' => $e->id, 'date' => '2026-07-15', 'mode' => 'project_based', 'hours_worked' => 8, 'status' => 'present']);
    expect((float) $row->total_amount)->toBe(50.0);

    // Back-date a raise to the 10th — July 15 is unpaid, so it reprices.
    $this->service->createRate($e, ['effective_from' => '2026-07-10', 'wage_type' => 'daily', 'rate' => '70']);

    $row->refresh();
    expect((float) $row->total_amount)->toBe(70.0);
});

// ── Validation + guards ─────────────────────────────────────────────────────

it('rejects a zero rate at the endpoint', function (): void {
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily', 'daily_wage' => '50']);

    $this->actingAs($this->admin)->post("/employees/{$e->id}/wage-rates", [
        'effective_from' => '2027-01-01',
        'wage_type' => 'daily',
        'rate' => 0,
    ])->assertSessionHasErrors('rate');

    expect(EmployeeWageRate::withoutGlobalScopes()->where('employee_id', $e->id)->count())->toBe(0);
});

it('refuses to delete a rate that has attendance', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily', 'daily_wage' => '50']);
    $rate = makeRate($e, 'daily', '50', '2026-07-01', null);

    app(AttendanceService::class)->create(['employee_id' => $e->id, 'date' => '2026-07-05', 'mode' => 'project_based', 'hours_worked' => 8, 'status' => 'present']);

    $this->delete("/employees/{$e->id}/wage-rates/{$rate->id}")->assertSessionHasErrors('wage_rate');

    expect(EmployeeWageRate::withoutGlobalScopes()->find($rate->id))->not->toBeNull();
});

it('deletes a historical rate with no attendance and re-opens the previous one', function (): void {
    $this->actingAs($this->admin);
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily', 'daily_wage' => '50']);
    $old = makeRate($e, 'daily', '50', '2026-07-01', '2026-07-10');
    $current = makeRate($e, 'daily', '70', '2026-07-11', null);

    $this->delete("/employees/{$e->id}/wage-rates/{$current->id}")->assertRedirect();

    $old->refresh();
    expect(EmployeeWageRate::withoutGlobalScopes()->find($current->id))->toBeNull()
        ->and($old->effective_to)->toBeNull(); // previous re-opened
});

// ── Tenancy isolation ───────────────────────────────────────────────────────

it('cannot add a rate to another company employee', function (): void {
    $foreign = Employee::factory()->forCompany($this->companyB)->create(['wage_type' => 'daily', 'daily_wage' => '50']);

    $this->actingAs($this->admin)->post("/employees/{$foreign->id}/wage-rates", [
        'effective_from' => '2027-01-01',
        'wage_type' => 'daily',
        'rate' => '70',
    ])->assertNotFound();

    expect(EmployeeWageRate::withoutGlobalScopes()->where('employee_id', $foreign->id)->count())->toBe(0);
});

it('cannot delete another company rate record', function (): void {
    $foreignAdmin = User::factory()->companyAdmin()->forCompany($this->companyB)->create();
    $e = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'daily', 'daily_wage' => '50']);
    $this->actingAs($this->admin);
    $rate = makeRate($e, 'daily', '50', '2026-07-01', null);

    $this->actingAs($foreignAdmin)->delete("/employees/{$e->id}/wage-rates/{$rate->id}")->assertNotFound();

    expect(EmployeeWageRate::withoutGlobalScopes()->find($rate->id))->not->toBeNull();
});
