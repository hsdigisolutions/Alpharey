<?php

use App\Enums\PayrollStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Illuminate\Support\Facades\DB;

/**
 * Change 5 — the payroll Calculate flow is synchronous and re-runnable. This
 * pins the per-employee synchronous recalculate button and the "notify once
 * on first generation, not on every re-run" guard.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->month = '2026-05';
});

/** An hourly employee with $days attendance days of 8h at 20/h in the test month. */
function recalcEmployee(int $days = 1): Employee
{
    $employee = Employee::factory()->forCompany(test()->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '20',
    ]);

    for ($d = 1; $d <= $days; $d++) {
        Attendance::factory()->create([
            'company_id' => test()->company->id,
            'employee_id' => $employee->id,
            'date' => sprintf('%s-%02d', test()->month, $d),
            'status' => 'present',
            'hours_worked' => '8',
            'overtime_hours' => '0',
            'hourly_rate_snapshot' => '20',
            'wage_rate_snapshot' => '20',
            'wage_type_snapshot' => 'hourly',
            'total_amount' => '160',
        ]);
    }

    return $employee;
}

it('recalculates a single employee synchronously and reflects new attendance', function (): void {
    $employee = recalcEmployee(days: 1);
    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $payroll->getAttribute('gross_pay'))->toBe(160.0);

    // A second day is worked AFTER the first calculation.
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id,
        'date' => "{$this->month}-02", 'status' => 'present',
        'hours_worked' => '8', 'overtime_hours' => '0',
        'hourly_rate_snapshot' => '20', 'wage_rate_snapshot' => '20',
        'wage_type_snapshot' => 'hourly', 'total_amount' => '160',
    ]);

    $this->actingAs($this->admin)
        ->post("/payroll/{$payroll->id}/recalculate")
        ->assertRedirect();

    expect((float) $payroll->refresh()->getAttribute('gross_pay'))->toBe(320.0);
});

it('refuses to recalculate a paid payroll row', function (): void {
    $employee = recalcEmployee();
    app(PayrollService::class)->calculateMonth($this->company->id, $this->month);
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    $payroll->status = PayrollStatus::Paid;
    $payroll->save();

    $this->actingAs($this->admin)
        ->post("/payroll/{$payroll->id}/recalculate")
        ->assertStatus(422);
});

it('cannot recalculate another company payroll row (tenancy 404)', function (): void {
    $otherCompany = Company::factory()->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create(['wage_type' => 'hourly', 'wage_rate' => '20']);
    $payroll = new Payroll(['month' => $this->month, 'status' => PayrollStatus::Pending]);
    $payroll->company_id = $otherCompany->id;
    $payroll->employee_id = $otherEmployee->id;
    $payroll->save();

    $this->actingAs($this->admin)
        ->post("/payroll/{$payroll->id}/recalculate")
        ->assertNotFound();
});

it('notifies once on first generation but not on re-runs', function (): void {
    recalcEmployee();

    $this->actingAs($this->admin)->post('/payroll/calculate', ['month' => $this->month])->assertRedirect();
    $afterFirst = DB::table('notifications')->count();
    expect($afterFirst)->toBeGreaterThan(0);

    // A re-run (refresh) of the SAME month must not re-notify.
    $this->actingAs($this->admin)->post('/payroll/calculate', ['month' => $this->month])->assertRedirect();
    expect(DB::table('notifications')->count())->toBe($afterFirst);
});
