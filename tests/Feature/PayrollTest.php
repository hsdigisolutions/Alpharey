<?php

use App\Enums\AdvanceStatus;
use App\Enums\PayrollStatus;
use App\Enums\WageType;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Expense;
use App\Models\LockedPeriod;
use App\Models\Measurement;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFine;
use App\Models\VehicleFuelRecord;
use App\Services\Payroll\PayrollService;
use App\Support\PeriodLock;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->month = '2026-05';
});

/**
 * Close a month. company_id is deliberately not mass-assignable on
 * company-owned models (it comes from the active company), so set it directly.
 */
function lockMonth(int $companyId, string $month): LockedPeriod
{
    $period = new LockedPeriod(['month' => $month, 'locked_at' => now()]);
    $period->company_id = $companyId;
    $period->save();

    app(PeriodLock::class)->forget();

    return $period;
}

/** An hourly employee with `$days` attendance days of `$hours` at `$rate`/h. */
function hourlyEmployee(float $rate = 20, int $days = 2, float $hours = 8, float $ot = 0): Employee
{
    $employee = Employee::factory()->forCompany(test()->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => (string) $rate,
    ]);

    for ($d = 1; $d <= $days; $d++) {
        Attendance::factory()->create([
            'company_id' => test()->company->id,
            'employee_id' => $employee->id,
            'date' => sprintf('%s-%02d', test()->month, $d),
            'status' => 'present',
            'hours_worked' => (string) $hours,
            'overtime_hours' => (string) $ot,
            'hourly_rate_snapshot' => (string) $rate,
            'wage_rate_snapshot' => (string) $rate,
            'wage_type_snapshot' => 'hourly',
            'total_amount' => (string) ($hours * $rate + $ot * $rate * 1.5),
        ]);
    }

    return $employee;
}

it('calculates gross and net from the frozen attendance snapshots', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 2, hours: 8);

    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    // 2 days x 8h x 20 = 320
    expect((float) $payroll->attendance_days)->toBe(2.0)
        ->and((float) $payroll->attendance_hours)->toBe(16.0)
        ->and((float) $payroll->getAttribute('hours_amount'))->toBe(320.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(320.0)
        ->and((float) $payroll->getAttribute('net_amount'))->toBe(320.0)
        ->and($payroll->status)->toBe(PayrollStatus::Pending);
});

it('uses the snapshot rate even after the employee gets a raise', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 1, hours: 8);

    $employee->update(['wage_rate' => '99']); // raise AFTER the days were worked

    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    // 8h x 20 (frozen), NOT 8h x 99
    expect((float) $payroll->getAttribute('gross_pay'))->toBe(160.0);
});

it('prices overtime from the frozen day total', function (): void {
    // 8h + 2h OT at 20/h with the default 1.5x -> base 160, OT 60
    $employee = hourlyEmployee(rate: 20, days: 1, hours: 8, ot: 2);

    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    expect((float) $payroll->getAttribute('hours_amount'))->toBe(160.0)
        ->and((float) $payroll->getAttribute('overtime_pay'))->toBe(60.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(220.0);
});

it('deducts approved advances earmarked for the month', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 2, hours: 8); // 320 gross

    Advance::factory()->approved()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'amount' => '50', 'payroll_month' => $this->month,
    ]);
    // A different month's advance must not touch this run
    Advance::factory()->approved()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'amount' => '999', 'payroll_month' => '2026-04',
    ]);
    // A pending advance is not yet deductible
    Advance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'amount' => '777', 'payroll_month' => $this->month,
    ]);

    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    expect((float) $payroll->getAttribute('advance_deductions'))->toBe(50.0)
        ->and((float) $payroll->getAttribute('net_amount'))->toBe(270.0); // 320 - 50
});

it('stores the Tarifa figure from the field matching the wage type', function (): void {
    // A daily worker whose hourly column is null (wage-history sync nulls the
    // non-matching columns): the payroll rate must show the DAILY wage, not 0.
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '50', 'wage_rate' => null,
    ]);
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => $this->month.'-04', 'status' => 'present', 'day_type' => 'full',
        'wage_type_snapshot' => 'daily', 'wage_rate_snapshot' => '50',
        'hourly_rate_snapshot' => '6.25', 'hours_worked' => '8', 'total_amount' => '50',
    ]);

    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $payroll->getAttribute('wage_rate'))->toBe(50.0); // daily_wage, not the null hourly column
});

it('pulls worker project expenses into the month', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 1, hours: 8); // 160 gross
    $project = Project::factory()->forCompany($this->company)->create();

    // Tagged to employee + project, employee-borne -> worker project expense (approved)
    $projectExpense = Expense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'project_id' => $project->id, 'bearable_by' => 'employee', 'is_reimbursable' => true,
        'date' => $this->month.'-05', 'total' => '40',
    ]);
    // Tagged to employee only + reimbursable -> reimbursement (approved)
    $reimbursement = Expense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'project_id' => null, 'is_reimbursable' => true,
        'date' => $this->month.'-06', 'total' => '10',
    ]);
    // approved is not mass-assignable (set by the approve endpoint) — set direct
    foreach ([$projectExpense, $reimbursement] as $e) {
        $e->approved = true;
        $e->save();
    }
    // A claim nobody has approved yet must NOT be paid back — same rule as
    // unapproved measurements.
    Expense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'project_id' => $project->id, 'date' => $this->month.'-07', 'total' => '999',
    ]);

    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    expect((float) $payroll->getAttribute('project_expenses'))->toBe(40.0)
        ->and((float) $payroll->getAttribute('reimbursements'))->toBe(10.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(210.0); // 160 + 40 + 10
});

it('pays a per-meter worker only for APPROVED measurements', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'per_meter', 'per_meter_rate' => '5',
    ]);
    $project = Project::factory()->forCompany($this->company)->create();

    $approved = new Measurement([
        'project_id' => $project->id, 'employee_id' => $employee->id,
        'date' => $this->month.'-10', 'quantity' => '30', 'measurement_type' => 'length',
    ]);
    $approved->company_id = $this->company->id;
    $approved->approved = true;
    $approved->save();

    $unapproved = new Measurement([
        'project_id' => $project->id, 'employee_id' => $employee->id,
        'date' => $this->month.'-11', 'quantity' => '100', 'measurement_type' => 'length',
    ]);
    $unapproved->company_id = $this->company->id;
    $unapproved->save();

    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    // 30m x 5 = 150; the unapproved 100m is not earned yet
    expect((float) $payroll->getAttribute('base_salary'))->toBe(150.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(150.0);
});

it('keeps a deployed worker on the HOME payroll with a transfer note (Option A)', function (): void {
    $host = Company::factory()->create();
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '20',
    ]);
    $hostProject = Project::factory()->forCompany($host)->create();

    EmployeeDeployment::factory()->create([
        'employee_id' => $employee->id,
        'home_company_id' => $this->company->id,
        'host_company_id' => $host->id,
        'project_id' => $hostProject->id,
        'deployment_start' => $this->month.'-01',
        'deployment_end' => $this->month.'-28',
    ]);

    // Days worked at the HOST are logged under the host's company_id...
    Attendance::factory()->create([
        'company_id' => $host->id, 'employee_id' => $employee->id,
        'project_id' => $hostProject->id, 'date' => $this->month.'-04',
        'status' => 'present', 'hours_worked' => '8', 'overtime_hours' => '0',
        'hourly_rate_snapshot' => '20', 'wage_type_snapshot' => 'hourly',
        'total_amount' => '160',
    ]);

    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    // ...but the HOME company still pays for them
    expect($payroll->company_id)->toBe($this->company->id)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(160.0)
        ->and($payroll->deployment_notes)->toHaveCount(1)
        ->and($payroll->deployment_notes[0])->toContain($host->name);
});

it('never rewrites a payroll that has already been paid', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 1, hours: 8);
    $service = app(PayrollService::class);

    $service->calculateMonth($this->company->id, $this->month);
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    $payroll->status = PayrollStatus::Paid;
    $payroll->save();

    // More attendance appears after payment, then someone recalculates
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => $this->month.'-20', 'status' => 'present', 'hours_worked' => '8',
        'overtime_hours' => '0', 'hourly_rate_snapshot' => '20', 'total_amount' => '160',
    ]);
    $service->calculateMonth($this->company->id, $this->month);

    // The paid figure is untouched
    expect((float) $payroll->fresh()->getAttribute('gross_pay'))->toBe(160.0);
});

it('preserves manual adjustments across a recalculation', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 1, hours: 8); // 160
    $service = app(PayrollService::class);
    $service->calculateMonth($this->company->id, $this->month);

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    $payroll->other_deductions = '10';
    $payroll->manual_additions = '25';
    $payroll->save();

    $service->calculateMonth($this->company->id, $this->month);
    $payroll->refresh();

    expect((float) $payroll->getAttribute('other_deductions'))->toBe(10.0)
        ->and((float) $payroll->getAttribute('manual_additions'))->toBe(25.0)
        ->and((float) $payroll->getAttribute('net_amount'))->toBe(175.0); // 160 - 10 + 25
});

it('marks paid and settles the advances it deducted', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 1, hours: 8);
    $advance = Advance::factory()->approved()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'amount' => '50', 'payroll_month' => $this->month,
    ]);

    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    $this->actingAs($this->admin)->post("/payroll/{$payroll->id}/paid", [
        'payment_method' => 'bank_transfer',
    ])->assertRedirect();

    expect($payroll->fresh()->status)->toBe(PayrollStatus::Paid)
        ->and($advance->fresh()->status)->toBe(AdvanceStatus::Deducted);
});

it('blocks locking a month while payrolls are still pending', function (): void {
    hourlyEmployee();
    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $this->actingAs($this->admin)
        ->post('/payroll/lock', ['month' => $this->month])
        ->assertSessionHasErrors('month');

    expect(LockedPeriod::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects attendance edits in a locked month, system-wide', function (): void {
    $employee = hourlyEmployee(days: 0);

    lockMonth($this->company->id, $this->month);

    // The lock is enforced in AttendanceService, not just on the payroll screen
    $this->actingAs($this->admin)->post('/attendance', [
        'employee_id' => $employee->id,
        'date' => $this->month.'-15',
        'mode' => 'hourly', 'check_in' => '09:00', 'check_out' => '17:00',
        'status' => 'present',
    ])->assertSessionHasErrors('date');

    expect(Attendance::withoutGlobalScopes()->where('date', $this->month.'-15')->count())->toBe(0);
});

it('refuses to calculate a locked month', function (): void {
    hourlyEmployee();
    lockMonth($this->company->id, $this->month);

    $this->actingAs($this->admin)
        ->post('/payroll/calculate', ['month' => $this->month])
        ->assertSessionHasErrors('month');
});

it('downloads an internal payslip PDF', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 1, hours: 8);
    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    $response = $this->actingAs($this->admin)->get("/payroll/{$payroll->id}/payslip");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('denies payroll without view permission', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->get('/payroll')->assertForbidden();
});

it('shows only the acting company payrolls', function (): void {
    hourlyEmployee();
    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $other = Company::factory()->create();
    $otherEmployee = Employee::factory()->forCompany($other)->create(['wage_type' => 'hourly', 'wage_rate' => '20']);
    app(PayrollService::class)->calculateFor($otherEmployee, $other->id, $this->month);

    $this->actingAs($this->admin)->get('/payroll?month='.$this->month)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Payroll/Index')->has('rows', 1));
});

it('does not pay a soft-deleted employee', function (): void {
    // Soft delete does not flip `active`, and calculateMonth used a bare
    // withoutGlobalScopes() — which strips SoftDeletes along with the tenant
    // scope. A deleted monthly-salaried worker (whose pay needs no attendance
    // rows) kept receiving a full payslip every month.
    $employee = Employee::factory()->for($this->company)->create([
        'wage_type' => WageType::Monthly,
        'base_salary' => '2000',
        'active' => true,
    ]);

    $employee->delete();

    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    expect(Payroll::query()->withoutGlobalScopes()
        ->where('employee_id', $employee->id)->where('month', $this->month)->exists())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// Vehicle fines never auto-deduct — the admin flags each one explicitly.
// ─────────────────────────────────────────────────────────────────────────────

it('approves and deducts an advance added straight from the payroll screen', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 2, hours: 8); // 320 gross

    // The Payroll page advance modal posts approve=true.
    $this->actingAs($this->admin)->post('/advances', [
        'employee_id' => $employee->id, 'amount' => '50',
        'request_date' => $this->month.'-03', 'payroll_month' => $this->month,
        'approve' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $advance = Advance::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect($advance->status)->toBe(AdvanceStatus::Approved);

    // calculateMonth ran on save, so the row already carries the deduction.
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $payroll->getAttribute('advance_deductions'))->toBe(50.0)
        ->and((float) $payroll->getAttribute('net_amount'))->toBe(270.0); // 320 - 50
});

it('leaves an advance Pending when not flagged for approval', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 1, hours: 8);

    $this->actingAs($this->admin)->post('/advances', [
        'employee_id' => $employee->id, 'amount' => '50',
        'request_date' => $this->month.'-03', 'payroll_month' => $this->month,
    ])->assertRedirect();

    expect(Advance::withoutGlobalScopes()->where('employee_id', $employee->id)->first()->status)
        ->toBe(AdvanceStatus::Pending);
});

it('never auto-deducts a vehicle fine charged to an employee', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 1, hours: 8); // 160 gross
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    VehicleFine::factory()->create([
        'company_id' => $this->company->id, 'vehicle_id' => $vehicle->id, 'employee_id' => $employee->id,
        'fine_date' => $this->month.'-10', 'amount' => '200', 'charged_to' => 'employee',
        // NOT flagged for salary deduction.
    ]);

    $payroll = app(PayrollService::class)->calculateFor($employee, $this->company->id, $this->month);

    expect((float) $payroll->getAttribute('fine_deductions'))->toBe(0.0)
        ->and((float) $payroll->getAttribute('net_amount'))->toBe(160.0);
});

it('deducts a vehicle fine only once the admin flags it for this month', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 1, hours: 8); // 160 gross
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    VehicleFine::factory()->create([
        'company_id' => $this->company->id, 'vehicle_id' => $vehicle->id, 'employee_id' => $employee->id,
        'fine_date' => $this->month.'-10', 'amount' => '50', 'charged_to' => 'employee',
        'deduct_from_salary' => true, 'deduction_month' => $this->month,
    ]);

    $payroll = app(PayrollService::class)->calculateFor($employee, $this->company->id, $this->month);

    expect((float) $payroll->getAttribute('fine_deductions'))->toBe(50.0)
        ->and((float) $payroll->getAttribute('net_amount'))->toBe(110.0);
});

it('reimburses worker-paid fuel but not company-card fuel', function (): void {
    $employee = hourlyEmployee(rate: 20, days: 1, hours: 8); // 160 gross
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    // Worker paid this one → reimbursed.
    VehicleFuelRecord::factory()->create([
        'company_id' => $this->company->id, 'vehicle_id' => $vehicle->id, 'employee_id' => $employee->id,
        'fuel_date' => $this->month.'-05', 'total_cost' => '40', 'payment_method' => 'reimburse',
    ]);
    // Company card → NOT reimbursed.
    VehicleFuelRecord::factory()->create([
        'company_id' => $this->company->id, 'vehicle_id' => $vehicle->id, 'employee_id' => $employee->id,
        'fuel_date' => $this->month.'-06', 'total_cost' => '55', 'payment_method' => 'company_card',
    ]);

    $payroll = app(PayrollService::class)->calculateFor($employee, $this->company->id, $this->month);

    expect((float) $payroll->getAttribute('reimbursements'))->toBe(40.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(200.0); // 160 + 40
});

it('re-derives a jornada left at 0 by the day-type back-fill from the daily rate', function (): void {
    // The day-type migration reclassified an old zero-priced row to day_type=full
    // without re-pricing it: total_amount stays 0. Payroll must still pay the day
    // from the daily rate rather than silently paying nothing.
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '50',
    ]);
    foreach ([3, 4, 6, 7] as $d) {
        Attendance::factory()->create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'date' => sprintf('%s-%02d', $this->month, $d), 'status' => 'present',
            'day_type' => 'full', 'hours_worked' => '7', 'total_amount' => '0',
            'wage_rate_snapshot' => '80', 'hourly_rate_snapshot' => null,
        ]);
    }

    $payroll = app(PayrollService::class)->calculateFor($employee, $this->company->id, $this->month);

    // 4 full days × the CURRENT daily rate (50) = 200 — not the stale 80, not 0.
    expect((float) $payroll->getAttribute('days_amount'))->toBe(200.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(200.0);
});
