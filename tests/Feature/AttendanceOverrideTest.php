<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '80',
    ]);
});

it('requires a reason when a manual wage override is set', function (): void {
    $this->actingAs($this->admin)->post('/attendance', [
        'employee_id' => $this->employee->id, 'date' => '2026-07-06',
        'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
        'manual_wage_override' => true, 'total_amount' => 200,
    ])->assertSessionHasErrors('override_reason');

    expect(Attendance::count())->toBe(0);
});

it('accepts a manual override with a reason and trusts the amount', function (): void {
    $this->actingAs($this->admin)->post('/attendance', [
        'employee_id' => $this->employee->id, 'date' => '2026-07-06',
        'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
        'manual_wage_override' => true, 'total_amount' => 200, 'override_reason' => 'Bono especial',
    ])->assertRedirect();

    $row = Attendance::withoutGlobalScopes()->firstOrFail();
    expect((float) $row->total_amount)->toBe(200.0)      // trusted, not the 80 daily rate
        ->and($row->override_reason)->toBe('Bono especial');
});
