<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Payroll\PayrollService;

// Item 8 (2026-09-13) — worker referral commission. The REFERRER earns a
// commission from the REFERRED worker's attendance, folded into the referrer's
// own monthly payroll. Additive; never touches the referred worker's pay.
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);
    $this->month = '2026-05';
});

/** A worker; pass referral terms to make them a REFERRED worker of $referrer. */
function refWorker(array $attrs = []): Employee
{
    return Employee::factory()->forCompany(test()->company)->create(array_merge([
        'wage_type' => 'daily', 'daily_wage' => '100', 'active' => true,
        'joining_date' => '2026-05-01',
    ], $attrs));
}

/** Log $days present days in $month for a worker (each a full jornada). */
function workDays(Employee $emp, int $days, string $month, float $hours = 8): void
{
    for ($d = 1; $d <= $days; $d++) {
        Attendance::factory()->create([
            'company_id' => test()->company->id,
            'employee_id' => $emp->id,
            'date' => sprintf('%s-%02d', $month, $d),
            'status' => 'present',
            'day_type' => 'hourly',
            'hours_worked' => (string) $hours,
            'total_amount' => '100',
        ]);
    }
}

function grossOf(Employee $emp): float
{
    $p = Payroll::withoutGlobalScopes()->where('employee_id', $emp->id)->firstOrFail();

    return (float) $p->getAttribute('gross_pay');
}

function referralOf(Employee $emp): float
{
    $p = Payroll::withoutGlobalScopes()->where('employee_id', $emp->id)->firstOrFail();

    return (float) $p->getAttribute('referral_commission');
}

it('pays per-day referral commission into the referrer payroll', function (): void {
    $referrer = refWorker(); // referrer needs no referral terms
    $referred = refWorker([
        'referred_by_employee_id' => $referrer->id,
        'referral_rate_type' => 'per_day', 'referral_amount' => '5', 'referral_window_months' => 6,
    ]);
    workDays($referred, 3, $this->month);

    app(PayrollService::class)->calculateFor($referrer, $this->company->id, $this->month);

    // 3 worked days × €5 = €15 referral commission on the referrer's payslip.
    expect(referralOf($referrer))->toBe(15.0)
        ->and(grossOf($referrer))->toBe(15.0); // referrer had no attendance of their own
});

it('pays per-hour referral commission on net worked hours', function (): void {
    $referrer = refWorker();
    $referred = refWorker([
        'referred_by_employee_id' => $referrer->id,
        'referral_rate_type' => 'per_hour', 'referral_amount' => '2', 'referral_window_months' => 6,
    ]);
    workDays($referred, 2, $this->month, hours: 8); // 16 net hours

    app(PayrollService::class)->calculateFor($referrer, $this->company->id, $this->month);

    expect(referralOf($referrer))->toBe(32.0); // 16 h × €2
});

it('pays a flat per-month commission for any month with worked days', function (): void {
    $referrer = refWorker();
    $referred = refWorker([
        'referred_by_employee_id' => $referrer->id,
        'referral_rate_type' => 'per_month', 'referral_amount' => '50', 'referral_window_months' => 6,
    ]);
    workDays($referred, 4, $this->month);

    app(PayrollService::class)->calculateFor($referrer, $this->company->id, $this->month);
    expect(referralOf($referrer))->toBe(50.0);

    // A month with NO worked days earns nothing.
    app(PayrollService::class)->calculateFor($referrer, $this->company->id, '2026-06');
    expect(referralOf($referrer->fresh()))->toBe(50.0); // still the May row read below

    $june = Payroll::withoutGlobalScopes()->where('employee_id', $referrer->id)->where('month', '2026-06')->firstOrFail();
    expect((float) $june->getAttribute('referral_commission'))->toBe(0.0);
});

it('pays a one-time commission only in the first month with real attendance', function (): void {
    $referrer = refWorker();
    $referred = refWorker([
        'referred_by_employee_id' => $referrer->id,
        'referral_rate_type' => 'one_time', 'referral_amount' => '200', 'referral_window_months' => 12,
    ]);
    workDays($referred, 2, '2026-05');
    workDays($referred, 3, '2026-06');

    app(PayrollService::class)->calculateFor($referrer, $this->company->id, '2026-05');
    app(PayrollService::class)->calculateFor($referrer, $this->company->id, '2026-06');

    $may = Payroll::withoutGlobalScopes()->where('employee_id', $referrer->id)->where('month', '2026-05')->firstOrFail();
    $june = Payroll::withoutGlobalScopes()->where('employee_id', $referrer->id)->where('month', '2026-06')->firstOrFail();

    expect((float) $may->getAttribute('referral_commission'))->toBe(200.0)   // first worked month
        ->and((float) $june->getAttribute('referral_commission'))->toBe(0.0); // not again
});

it('stops paying after the window expires', function (): void {
    $referrer = refWorker();
    // Joined Jan, window 3 months → earns Jan/Feb/Mar; May is expired.
    $referred = refWorker([
        'joining_date' => '2026-01-01',
        'referred_by_employee_id' => $referrer->id,
        'referral_rate_type' => 'per_day', 'referral_amount' => '5', 'referral_window_months' => 3,
    ]);
    workDays($referred, 3, $this->month); // May

    app(PayrollService::class)->calculateFor($referrer, $this->company->id, $this->month);
    expect(referralOf($referrer))->toBe(0.0);
});

it('does not accrue when either party is inactive', function (): void {
    // Inactive referred worker.
    $referrer = refWorker();
    $referred = refWorker([
        'active' => false,
        'referred_by_employee_id' => $referrer->id,
        'referral_rate_type' => 'per_day', 'referral_amount' => '5', 'referral_window_months' => 6,
    ]);
    workDays($referred, 3, $this->month);
    app(PayrollService::class)->calculateFor($referrer, $this->company->id, $this->month);
    expect(referralOf($referrer))->toBe(0.0);

    // Inactive referrer.
    $referrer2 = refWorker(['active' => false]);
    $referred2 = refWorker([
        'referred_by_employee_id' => $referrer2->id,
        'referral_rate_type' => 'per_day', 'referral_amount' => '5', 'referral_window_months' => 6,
    ]);
    workDays($referred2, 3, $this->month);
    app(PayrollService::class)->calculateFor($referrer2, $this->company->id, $this->month);
    expect(referralOf($referrer2))->toBe(0.0);
});

it('never changes the referred worker or an unrelated employee payroll', function (): void {
    $referrer = refWorker();
    $referred = refWorker([
        'referred_by_employee_id' => $referrer->id,
        'referral_rate_type' => 'per_day', 'referral_amount' => '5', 'referral_window_months' => 6,
    ]);
    $unrelated = refWorker();
    workDays($referred, 3, $this->month);
    workDays($unrelated, 2, $this->month);

    app(PayrollService::class)->calculateFor($referred, $this->company->id, $this->month);
    app(PayrollService::class)->calculateFor($unrelated, $this->company->id, $this->month);

    // Neither the referred worker nor an unrelated worker earns referral money.
    expect(referralOf($referred))->toBe(0.0)
        ->and(referralOf($unrelated))->toBe(0.0);
});

it('rejects a self-referral and a foreign-company referrer', function (): void {
    $other = Company::factory()->create();
    $foreign = Employee::factory()->forCompany($other)->create();
    $employee = refWorker();

    // Self-referral refused.
    $this->put("/employees/{$employee->id}", [
        'full_name' => $employee->full_name,
        'referred_by_employee_id' => $employee->id,
    ])->assertSessionHasErrors('referred_by_employee_id');

    // Another company's worker refused.
    $this->put("/employees/{$employee->id}", [
        'full_name' => $employee->full_name,
        'referred_by_employee_id' => $foreign->id,
    ])->assertSessionHasErrors('referred_by_employee_id');
});

it('requires an amount when a referral rate type is set', function (): void {
    $employee = refWorker();
    $referrer = refWorker();

    $this->put("/employees/{$employee->id}", [
        'full_name' => $employee->full_name,
        'referred_by_employee_id' => $referrer->id,
        'referral_rate_type' => 'per_day', // no amount
    ])->assertSessionHasErrors('referral_amount');
});
