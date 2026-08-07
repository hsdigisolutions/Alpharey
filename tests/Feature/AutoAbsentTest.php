<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->weekday = Carbon::parse('2026-08-01')->next('Monday')->toDateString();
    $this->weekend = Carbon::parse('2026-08-01')->next('Saturday')->toDateString();
});

it('books an absence for an active employee with no record on a weekday', function (): void {
    $e = Employee::factory()->forCompany($this->company)->create(['active' => true, 'joining_date' => '2026-01-01']);

    $this->artisan('attendance:auto-absent', ['--date' => $this->weekday])->assertSuccessful();

    $row = Attendance::withoutGlobalScopes()->where('employee_id', $e->id)->firstOrFail();
    expect($row->status->value)->toBe('absent')
        ->and($row->is_auto_generated)->toBeTrue()
        ->and((float) $row->total_amount)->toBe(0.0)
        ->and((float) $row->hours_worked)->toBe(0.0);
});

it('never auto-absents on a weekend', function (): void {
    Employee::factory()->forCompany($this->company)->create(['active' => true, 'joining_date' => '2026-01-01']);

    $this->artisan('attendance:auto-absent', ['--date' => $this->weekend])->assertSuccessful();

    expect(Attendance::withoutGlobalScopes()->count())->toBe(0);
});

it('skips an employee who already has a record that day', function (): void {
    $e = Employee::factory()->forCompany($this->company)->create(['active' => true, 'joining_date' => '2026-01-01']);
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => $this->weekday, 'status' => 'present',
    ]);

    $this->artisan('attendance:auto-absent', ['--date' => $this->weekday])->assertSuccessful();

    // Only the original present row — no auto-absence added.
    expect(Attendance::withoutGlobalScopes()->where('employee_id', $e->id)->count())->toBe(1)
        ->and(Attendance::withoutGlobalScopes()->where('employee_id', $e->id)->where('status', 'absent')->exists())->toBeFalse();
});

it('skips an inactive employee', function (): void {
    Employee::factory()->forCompany($this->company)->create(['active' => false, 'joining_date' => '2026-01-01']);

    $this->artisan('attendance:auto-absent', ['--date' => $this->weekday])->assertSuccessful();

    expect(Attendance::withoutGlobalScopes()->count())->toBe(0);
});

it('skips a new hire whose joining date is after the day', function (): void {
    Employee::factory()->forCompany($this->company)->create([
        'active' => true, 'joining_date' => Carbon::parse($this->weekday)->addWeek()->toDateString(),
    ]);

    $this->artisan('attendance:auto-absent', ['--date' => $this->weekday])->assertSuccessful();

    expect(Attendance::withoutGlobalScopes()->count())->toBe(0);
});

it('clears the auto flag once an admin edits the row', function (): void {
    $admin = App\Models\User::factory()->companyAdmin()->forCompany($this->company)->create();
    $e = Employee::factory()->forCompany($this->company)->create([
        'active' => true, 'joining_date' => '2026-01-01', 'wage_type' => 'daily', 'daily_wage' => '80',
    ]);
    $this->artisan('attendance:auto-absent', ['--date' => $this->weekday])->assertSuccessful();
    $row = Attendance::withoutGlobalScopes()->where('employee_id', $e->id)->firstOrFail();

    // Admin corrects it to a present full day.
    $this->actingAs($admin)->put("/attendance/{$row->id}", [
        'employee_id' => $e->id, 'date' => $this->weekday,
        'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
    ])->assertRedirect();

    $row->refresh();
    expect($row->is_auto_generated)->toBeFalse()
        ->and($row->status->value)->toBe('present');
});
