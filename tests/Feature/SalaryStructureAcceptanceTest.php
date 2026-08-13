<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeWageRate;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Payroll\PayrollService;

/**
 * The salary-structure spec's ACCEPTANCE TESTS (2026-08-12), with the client's
 * exact numbers. Tests 4, 6, 7, 8, 9, 10 and 11 are pinned in PayrollTest,
 * DesignationRateTest, ProfitabilityDailyTest, ProfitabilityTest and
 * EmployeesTest — this file carries the remaining exact-figure cases.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);
    $this->svc = app(AttendanceService::class);
});

/** An effective-dated wage-history row, bypassing the service (fixture only). */
function historyRate(Employee $employee, string $rate, string $from, ?string $to): void
{
    $row = new EmployeeWageRate([
        'wage_type' => 'daily', 'rate' => $rate,
        'effective_from' => $from, 'is_default' => $to === null,
    ]);
    $row->effective_to = $to;
    $row->employee_id = $employee->id;
    $row->company_id = $employee->company_id;
    $row->saveQuietly();
}

function payrollFor(Employee $employee, string $month): Payroll
{
    app(PayrollService::class)->calculateMonth($employee->company_id, $month);

    return Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
}

it('acceptance 1: daily worker, 4 full days at 50 €/day = 200,00 € (not 0)', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '50',
    ]);

    foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04'] as $d) {
        $this->svc->create(['employee_id' => $employee->id, 'date' => $d,
            'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present', 'hours_worked' => 8]);
    }

    $payroll = payrollFor($employee, '2026-06');

    expect((float) $payroll->getAttribute('days_amount'))->toBe(200.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(200.0);
});

it('acceptance 2: 3 full days + 1 half day at 50 €/day = 175,00 €', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '50',
    ]);

    foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $d) {
        $this->svc->create(['employee_id' => $employee->id, 'date' => $d,
            'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present', 'hours_worked' => 8]);
    }
    $this->svc->create(['employee_id' => $employee->id, 'date' => '2026-06-04',
        'mode' => 'project_based', 'day_type' => 'half', 'status' => 'present', 'hours_worked' => 4]);

    $payroll = payrollFor($employee, '2026-06');

    expect((float) $payroll->getAttribute('gross_pay'))->toBe(175.0); // 150 + 25
});

it('acceptance 3: hourly worker, 8 hours at 12,50 €/h = 100,00 €', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '12.50',
    ]);

    $this->svc->create(['employee_id' => $employee->id, 'date' => '2026-06-02',
        'mode' => 'hourly', 'day_type' => 'hourly', 'status' => 'present', 'hours_worked' => 8]);

    $payroll = payrollFor($employee, '2026-06');

    expect((float) $payroll->getAttribute('hours_amount'))->toBe(100.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(100.0);
});

it('acceptance 5: three rate changes in one month = 1.450 € across 3 payslip periods', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '70', // live column = the current (last) rate
    ]);
    historyRate($employee, '50', '2026-06-01', '2026-06-10');
    historyRate($employee, '60', '2026-06-11', '2026-06-20');
    historyRate($employee, '70', '2026-06-21', null);

    // 8 present days @50, 7 @60, 9 @70 → 400 + 420 + 630 = 1.450 €.
    $spans = [
        ['50', ['01', '02', '03', '04', '05', '06', '07', '08']],
        ['60', ['11', '12', '13', '14', '15', '16', '17']],
        ['70', ['21', '22', '23', '24', '25', '26', '27', '28', '29']],
    ];
    foreach ($spans as [$rate, $days]) {
        foreach ($days as $d) {
            $this->svc->create(['employee_id' => $employee->id, 'date' => "2026-06-{$d}",
                'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present', 'hours_worked' => 8]);
        }
    }

    $payroll = payrollFor($employee, '2026-06');
    $periods = $payroll->rate_periods;

    expect((float) $payroll->getAttribute('days_amount'))->toBe(1450.0)
        ->and($periods)->toHaveCount(3)
        ->and((float) $periods[0]['amount'])->toBe(400.0)
        ->and((float) $periods[1]['amount'])->toBe(420.0)
        ->and((float) $periods[2]['amount'])->toBe(630.0);
});

it('acceptance 12: cannot reach another company wage records (404)', function (): void {
    $other = Company::factory()->create();
    $foreignEmployee = Employee::factory()->forCompany($other)->create();
    $foreignRate = new EmployeeWageRate([
        'wage_type' => 'daily', 'rate' => '50', 'effective_from' => '2026-01-01', 'is_default' => true,
    ]);
    $foreignRate->employee_id = $foreignEmployee->id;
    $foreignRate->company_id = $other->id;
    $foreignRate->saveQuietly();

    // Creating a rate for another company's employee → 404 (scope hides them).
    $this->post("/employees/{$foreignEmployee->id}/wage-rates", [
        'rate' => 99, 'wage_type' => 'daily', 'effective_from' => '2026-06-01',
    ])->assertNotFound();

    // Deleting another company's rate row → 404 as well.
    $own = Employee::factory()->forCompany($this->company)->create();
    $this->delete("/employees/{$own->id}/wage-rates/{$foreignRate->id}")->assertNotFound();

    expect(EmployeeWageRate::withoutGlobalScopes()->whereKey($foreignRate->id)->exists())->toBeTrue()
        ->and(EmployeeWageRate::withoutGlobalScopes()->where('employee_id', $foreignEmployee->id)->count())->toBe(1);
});
