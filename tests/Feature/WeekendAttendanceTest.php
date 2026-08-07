<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Payroll\PayrollService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);
    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '80',
    ]);
    $this->saturday = Carbon::parse('2026-07-01')->next('Saturday')->toDateString();
    $this->weekday = Carbon::parse('2026-07-01')->next('Wednesday')->toDateString();
});

function weekendDay(int $employeeId, string $date, ?string $rateType = null, $amount = null): Attendance
{
    return app(AttendanceService::class)->create([
        'employee_id' => $employeeId, 'date' => $date,
        'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
        'weekend_rate_type' => $rateType, 'weekend_rate_amount' => $amount,
    ]);
}

it('auto-detects a weekend day server-side', function (): void {
    $sat = weekendDay($this->employee->id, $this->saturday);
    $wed = weekendDay($this->employee->id, $this->weekday);

    expect($sat->is_weekend)->toBeTrue()
        ->and($wed->is_weekend)->toBeFalse();
});

it('never trusts is_weekend from the client', function (): void {
    // The request cannot set is_weekend; the date decides.
    $this->post('/attendance', [
        'employee_id' => $this->employee->id, 'date' => $this->saturday,
        'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
        'is_weekend' => false, // ignored
    ])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->where('date', $this->saturday)->firstOrFail();
    expect($row->is_weekend)->toBeTrue();
});

it('prices a weekend full day at x1.5', function (): void {
    $row = weekendDay($this->employee->id, $this->saturday, 'x1.5');
    expect((float) $row->total_amount)->toBe(120.0); // 80 × 1.5
});

it('prices a weekend full day at x2', function (): void {
    $row = weekendDay($this->employee->id, $this->saturday, 'x2');
    expect((float) $row->total_amount)->toBe(160.0); // 80 × 2
});

it('prices a weekend day at a custom flat amount', function (): void {
    $row = weekendDay($this->employee->id, $this->saturday, 'custom', 200);
    expect((float) $row->total_amount)->toBe(200.0);
});

it('does not apply a premium on a weekday even if a rate type is sent', function (): void {
    $row = weekendDay($this->employee->id, $this->weekday, 'x2');
    expect((float) $row->total_amount)->toBe(80.0); // unchanged — not a weekend
});

it('separates weekend days on the payroll day-type summary', function (): void {
    $e = $this->employee;
    // Two weekday full days (80 each) + one weekend day at x1.5 (120).
    weekendDay($e->id, $this->weekday, null);
    weekendDay($e->id, Carbon::parse($this->weekday)->addDay()->toDateString(), null);
    weekendDay($e->id, $this->saturday, 'x1.5');

    app(PayrollService::class)->calculateMonth($this->company->id, '2026-07');
    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $e->id)->firstOrFail();
    $summary = collect($payroll->day_type_summary);

    $weekday = $summary->firstWhere('weekend', false);
    $weekend = $summary->firstWhere('weekend', true);

    expect((float) $payroll->gross_pay)->toBe(280.0) // 80 + 80 + 120
        ->and((float) $weekday['amount'])->toBe(160.0)
        ->and((float) $weekend['amount'])->toBe(120.0)
        ->and($weekend['weekend'])->toBeTrue();
});
