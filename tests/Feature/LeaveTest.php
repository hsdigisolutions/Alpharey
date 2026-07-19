<?php

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Enums\WageType;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveCategory;
use App\Models\LockedPeriod;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Leave\LeaveService;
use App\Services\Payroll\PayrollService;
use App\Support\PeriodLock;
use Illuminate\Validation\ValidationException;

/**
 * Screen 22. The interesting tests here are not the CRUD ones — they are the
 * ones pinning that approved leave reaches attendance (and therefore payroll)
 * with the right money attached, and no money attached where a salary already
 * covers the day.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create([
        'role' => UserRole::CompanyAdmin,
        'company_id' => $this->company->id,
    ]);
    $this->actingAs($this->admin);

    $this->category = LeaveCategory::factory()->create([
        'key' => 'annual',
        'name' => 'Vacaciones Anuales',
        'default_allocation' => '20',
        'is_paid' => true,
    ]);
});

/**
 * A Monday-to-Wednesday span, so the weekday/weekend logic is unambiguous.
 */
function leaveFor(array $overrides = []): Leave
{
    $monday = now()->startOfMonth()->next('Monday');

    return Leave::factory()->create(array_merge([
        'company_id' => test()->company->id,
        'leave_category_id' => test()->category->id,
        'start_date' => $monday->toDateString(),
        'end_date' => $monday->copy()->addDays(2)->toDateString(),
        'total_days' => '3',
    ], $overrides));
}

it('books approved leave into the attendance grid as leave days', function (): void {
    $employee = Employee::factory()->create([
        'company_id' => $this->company->id,
        'wage_type' => WageType::Monthly,
    ]);
    $leave = leaveFor(['employee_id' => $employee->id]);

    app(LeaveService::class)->approve($leave);

    $rows = Attendance::query()->withoutGlobalScopes()
        ->where('employee_id', $employee->id)->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->every(fn (Attendance $a) => $a->status === AttendanceStatus::Leave))->toBeTrue()
        ->and($leave->fresh()->status)->toBe(LeaveStatus::Approved);
});

it('skips weekends when booking the days', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $friday = now()->startOfMonth()->next('Friday');

    // Friday -> Monday spans a weekend: only 2 cells should be written.
    $leave = leaveFor([
        'employee_id' => $employee->id,
        'start_date' => $friday->toDateString(),
        'end_date' => $friday->copy()->addDays(3)->toDateString(),
        'total_days' => '2',
    ]);

    app(LeaveService::class)->approve($leave);

    expect(Attendance::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(2);
});

/**
 * The money rules. These are the ones worth breaking the build over.
 */
it('pays a leave day for a daily worker from the frozen wage snapshot', function (): void {
    $employee = Employee::factory()->create([
        'company_id' => $this->company->id,
        'wage_type' => WageType::Daily,
        'daily_wage' => 80,
    ]);

    app(LeaveService::class)->approve(leaveFor(['employee_id' => $employee->id]));

    $row = Attendance::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->first();

    // 8h x (80/8) = 80 for the day
    expect((float) $row->hours_worked)->toBe(8.0)
        ->and((float) $row->total_amount)->toBe(80.0);
});

it('books no pay for a monthly worker, whose salary already covers the day', function (): void {
    $employee = Employee::factory()->create([
        'company_id' => $this->company->id,
        'wage_type' => WageType::Monthly,
        'base_salary' => 2000,
    ]);

    app(LeaveService::class)->approve(leaveFor(['employee_id' => $employee->id]));

    $row = Attendance::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->first();

    expect((float) $row->total_amount)->toBe(0.0)
        ->and((float) $row->hours_worked)->toBe(0.0);
});

it('books no pay for unpaid leave', function (): void {
    $unpaid = LeaveCategory::factory()->unpaid()->create(['key' => 'unpaid']);
    $employee = Employee::factory()->create([
        'company_id' => $this->company->id,
        'wage_type' => WageType::Daily,
        'daily_wage' => 80,
    ]);

    app(LeaveService::class)->approve(leaveFor([
        'employee_id' => $employee->id,
        'leave_category_id' => $unpaid->id,
    ]));

    $row = Attendance::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->first();

    expect((float) $row->total_amount)->toBe(0.0);
});

/**
 * Payroll splits every attendance row as `overtime = total - base`. A leave
 * row carrying pay without matching hours would therefore surface as overtime
 * on a payslip — silently, and only visible as money.
 */
it('never lets a leave day masquerade as overtime in payroll', function (): void {
    $employee = Employee::factory()->create([
        'company_id' => $this->company->id,
        'wage_type' => WageType::Hourly,
        'wage_rate' => 12,
    ]);

    app(LeaveService::class)->approve(leaveFor(['employee_id' => $employee->id]));

    $rows = Attendance::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->get();

    foreach ($rows as $row) {
        $base = (float) $row->hours_worked * (float) $row->hourly_rate_snapshot;
        expect((float) max(0, (float) $row->total_amount - $base))->toBe(0.0);
    }
});

it('refuses to approve over a day that already has attendance', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $monday = now()->startOfMonth()->next('Monday');

    Attendance::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'date' => $monday->toDateString(),
    ]);

    $leave = leaveFor(['employee_id' => $employee->id]);

    expect(fn () => app(LeaveService::class)->approve($leave))
        ->toThrow(ValidationException::class);

    expect($leave->fresh()->status)->toBe(LeaveStatus::Pending);
});

it('refuses to approve leave into a locked month', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $leave = leaveFor(['employee_id' => $employee->id]);

    // company_id is not mass assignable — set it directly (project convention)
    $lock = new LockedPeriod(['month' => now()->format('Y-m')]);
    $lock->company_id = $this->company->id;
    $lock->save();
    app(PeriodLock::class)->forget();

    expect(fn () => app(LeaveService::class)->approve($leave))
        ->toThrow(ValidationException::class);

    expect(Attendance::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('withdraws the attendance rows when an approved leave is cancelled', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $leave = leaveFor(['employee_id' => $employee->id]);
    $service = app(LeaveService::class);

    $service->approve($leave);
    expect(Attendance::query()->withoutGlobalScopes()->count())->toBe(3);

    $service->cancel($leave->fresh());

    expect(Attendance::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($leave->fresh()->status)->toBe(LeaveStatus::Cancelled);
});

/**
 * Balances. Pending days are held immediately so two requests that each fit
 * cannot both be approved when together they do not.
 */
it('holds pending days against the balance and releases them on rejection', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $service = app(LeaveService::class);

    $leave = $service->request([
        'employee_id' => $employee->id,
        'leave_category_id' => $this->category->id,
        'start_date' => now()->startOfMonth()->next('Monday')->toDateString(),
        'end_date' => now()->startOfMonth()->next('Monday')->addDays(2)->toDateString(),
        'total_days' => '3',
    ]);

    $balance = LeaveBalance::query()->where('employee_id', $employee->id)->first();
    expect((float) $balance->pending)->toBe(3.0)
        ->and($balance->remaining())->toBe(17.0); // 20 allocated - 3 held

    $service->reject($leave->fresh());

    $balance->refresh();
    expect((float) $balance->pending)->toBe(0.0)
        ->and($balance->remaining())->toBe(20.0);
});

it('moves days from pending to used on approval', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $service = app(LeaveService::class);

    $leave = $service->request([
        'employee_id' => $employee->id,
        'leave_category_id' => $this->category->id,
        'start_date' => now()->startOfMonth()->next('Monday')->toDateString(),
        'end_date' => now()->startOfMonth()->next('Monday')->addDays(2)->toDateString(),
        'total_days' => '3',
    ]);

    $service->approve($leave->fresh());

    $balance = LeaveBalance::query()->where('employee_id', $employee->id)->first();

    expect((float) $balance->pending)->toBe(0.0)
        ->and((float) $balance->used)->toBe(3.0)
        ->and($balance->remaining())->toBe(17.0);
});

it('cannot review a request twice', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $leave = leaveFor(['employee_id' => $employee->id]);
    $service = app(LeaveService::class);

    $service->approve($leave);

    expect(fn () => $service->approve($leave->fresh()))->toThrow(ValidationException::class);
});

/**
 * The end-to-end claim of this phase: approved leave reaches PAY. Not "an
 * attendance row exists" — actual money on an actual payroll run.
 */
it('carries a paid leave day through to the payroll run', function (): void {
    $employee = Employee::factory()->create([
        'company_id' => $this->company->id,
        'wage_type' => WageType::Daily,
        'daily_wage' => 80,
        'active' => true,
    ]);

    app(LeaveService::class)->approve(leaveFor(['employee_id' => $employee->id]));

    app(PayrollService::class)
        ->calculateMonth($this->company->id, now()->format('Y-m'));

    $payroll = Payroll::query()->withoutGlobalScopes()
        ->where('employee_id', $employee->id)->first();

    // 3 leave days x 80 = 240, in the days line — and NOT in overtime.
    expect((float) $payroll->getAttribute('days_amount'))->toBe(240.0)
        ->and((float) $payroll->getAttribute('overtime_pay'))->toBe(0.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(240.0)
        // leave is not a worked day, so the day COUNT excludes it
        ->and((float) $payroll->getAttribute('attendance_days'))->toBe(0.0);
});

it('leaves a monthly salary untouched when paid leave is booked', function (): void {
    $employee = Employee::factory()->create([
        'company_id' => $this->company->id,
        'wage_type' => WageType::Monthly,
        'base_salary' => 2000,
        'active' => true,
    ]);

    app(LeaveService::class)->approve(leaveFor(['employee_id' => $employee->id]));

    app(PayrollService::class)
        ->calculateMonth($this->company->id, now()->format('Y-m'));

    $payroll = Payroll::query()->withoutGlobalScopes()
        ->where('employee_id', $employee->id)->first();

    // The salary covers the leave; nothing is added and nothing is lost.
    expect((float) $payroll->getAttribute('base_salary'))->toBe(2000.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(2000.0)
        ->and((float) $payroll->getAttribute('overtime_pay'))->toBe(0.0);
});

/**
 * Tenancy + permissions (dev-skill Rule 11 — mandatory for every module).
 */
it('shows a user only the leave of their own company', function (): void {
    $other = Company::factory()->create();
    leaveFor(['employee_id' => Employee::factory()->create(['company_id' => $this->company->id])->id]);
    Leave::factory()->create([
        'company_id' => $other->id,
        'employee_id' => Employee::factory()->create(['company_id' => $other->id])->id,
        'leave_category_id' => $this->category->id,
    ]);

    expect(Leave::query()->count())->toBe(1);
});

it('cannot reach another company leave by id', function (): void {
    $other = Company::factory()->create();
    $foreign = Leave::factory()->create([
        'company_id' => $other->id,
        'employee_id' => Employee::factory()->create(['company_id' => $other->id])->id,
        'leave_category_id' => $this->category->id,
    ]);

    $this->post("/leave/{$foreign->id}/approve")->assertNotFound();
});

it('ignores a company_id supplied in request input', function (): void {
    $other = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $monday = now()->startOfMonth()->next('Monday');

    $this->post('/leave', [
        'company_id' => $other->id, // malicious
        'employee_id' => $employee->id,
        'leave_category_id' => $this->category->id,
        'start_date' => $monday->toDateString(),
        'end_date' => $monday->toDateString(),
        'total_days' => '1',
    ])->assertRedirect();

    expect(Leave::query()->withoutGlobalScopes()->first()->company_id)->toBe($this->company->id);
});

it('denies leave actions to a user without the permission', function (): void {
    $plain = User::factory()->create([
        'role' => UserRole::User,
        'company_id' => $this->company->id,
    ]);
    $leave = leaveFor(['employee_id' => Employee::factory()->create(['company_id' => $this->company->id])->id]);

    $this->actingAs($plain)->get('/leave')->assertForbidden();
    $this->actingAs($plain)->post("/leave/{$leave->id}/approve")->assertForbidden();
});

it('refuses a leave request against another company employee', function (): void {
    $other = Company::factory()->create();
    $foreignEmployee = Employee::factory()->create(['company_id' => $other->id]);
    $monday = now()->startOfMonth()->next('Monday');

    $this->post('/leave', [
        'employee_id' => $foreignEmployee->id,
        'leave_category_id' => $this->category->id,
        'start_date' => $monday->toDateString(),
        'end_date' => $monday->toDateString(),
        'total_days' => '1',
    ])->assertSessionHasErrors('employee_id');
});

it('refuses to burn more days than the request actually spans', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $monday = now()->startOfMonth()->next('Monday');

    $this->post('/leave', [
        'employee_id' => $employee->id,
        'leave_category_id' => $this->category->id,
        'start_date' => $monday->toDateString(),
        'end_date' => $monday->toDateString(),
        'total_days' => '20', // one day span, twenty days claimed
    ])->assertSessionHasErrors('total_days');
});

it('translates the seeded leave categories but keeps a company custom name', function (): void {
    // The 8 defaults store a Spanish name in the DB, so an English user was
    // reading "Vacaciones Anuales" in every dropdown. A company's own category
    // has no translation key and must keep exactly what it was named.
    $seeded = LeaveCategory::factory()->create(['key' => 'annual', 'name' => 'Vacaciones Anuales', 'company_id' => null]);
    $own = LeaveCategory::factory()->create(['key' => 'site_visit', 'name' => 'Visita de Obra']);

    app()->setLocale('en');
    expect($seeded->label())->toBe('Annual Leave')
        ->and($own->label())->toBe('Visita de Obra');

    app()->setLocale('es');
    expect($seeded->label())->toBe('Vacaciones Anuales');
});
