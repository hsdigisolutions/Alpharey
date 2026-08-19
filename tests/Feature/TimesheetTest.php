<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Feature 3 — weekly/monthly per-employee timesheet.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('renders a weekly timesheet with per-day rows, total and days present', function (): void {
    // Mon 10 + Tue 11 Aug 2026 worked; the rest of the week empty.
    foreach (['2026-08-10' => '8', '2026-08-11' => '8.5'] as $date => $hours) {
        Attendance::factory()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => $date, 'status' => 'present', 'hours_worked' => $hours,
        ]);
    }

    $this->actingAs($this->admin)
        ->get("/timesheet?employee={$this->employee->id}&mode=week&date=2026-08-12")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Timesheet/Index')
            ->has('sheet.rows', 7) // Mon–Sun
            ->where('sheet.total_hours', 16.5)
            ->where('sheet.days_present', 2));
});

it('exports the timesheet as Excel and PDF', function (): void {
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'date' => '2026-08-10', 'status' => 'present', 'hours_worked' => '8',
    ]);

    $q = "employee={$this->employee->id}&mode=week&date=2026-08-12";
    $this->actingAs($this->admin)->get("/timesheet/export?{$q}&format=excel")->assertOk();
    $this->actingAs($this->admin)->get("/timesheet/export?{$q}&format=pdf")->assertOk();
});

it('denies the timesheet without attendance.view', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->get('/timesheet')->assertForbidden();
});
