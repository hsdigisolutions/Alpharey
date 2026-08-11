<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\Attendance\AttendanceService;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'daily', 'daily_wage' => '80',
    ]);
});

it('returns a month grid and summary on the attendance tab', function (): void {
    $this->actingAs($this->admin);
    app(AttendanceService::class)->create([
        'employee_id' => $this->employee->id, 'date' => '2026-07-06',
        'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
    ]);

    $this->get("/employees/{$this->employee->id}?att_month=2026-07")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('attendanceTab.month', '2026-07')
            ->where('attendanceTab.summary.present', 1)
            ->where('attendanceTab.summary.half_days', 0)
            ->where('attendanceTab.summary.total_wage', 80)
            ->where('attendanceTab.grid.6.day_type', 'full')
            ->where('attendanceTab.grid.6.total', 80)
        );
});

it('marks an unrecorded past weekday as a live absence on the tab', function (): void {
    $this->actingAs($this->admin);
    // today = Fri 3 Jul 2026; joined Wed 1 Jul. Wed present, Thu no record → a
    // live absence; Fri (today) is still in progress.
    $this->travelTo('2026-07-03 10:00');
    $this->employee->update(['joining_date' => '2026-07-01']);
    app(AttendanceService::class)->create([
        'employee_id' => $this->employee->id, 'date' => '2026-07-01',
        'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
    ]);

    $this->get("/employees/{$this->employee->id}?att_month=2026-07")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('attendanceTab.summary.present', 1)
            ->where('attendanceTab.summary.absences', 1)        // Thu 2 Jul
            ->where('attendanceTab.summary.auto_absences', 1)
            ->where('attendanceTab.grid.2.status', 'absent')
            ->where('attendanceTab.grid.2.is_auto', true)
            ->where('attendanceTab.grid.2.id', null)
        );

    $this->travelBack();
});

it('hides the wage total from a viewer without wage access', function (): void {
    // A manager with employees.view + attendance.view but NOT payroll.view / employees.edit.
    $user = User::factory()->forCompany($this->company)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id,
        'module' => 'employees', 'can_view' => true,
    ]);
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id,
        'module' => 'attendance', 'can_view' => true,
    ]);

    $this->actingAs($this->admin);
    app(AttendanceService::class)->create([
        'employee_id' => $this->employee->id, 'date' => '2026-07-06',
        'mode' => 'project_based', 'day_type' => 'full', 'status' => 'present',
    ]);

    $this->actingAs($user)->get("/employees/{$this->employee->id}?att_month=2026-07")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('attendanceTab.summary.present', 1)
            ->where('attendanceTab.summary.total_wage', null)
            ->where('attendanceTab.grid.6.total', null)
        );
});

it('withholds the attendance tab from a user without attendance.view', function (): void {
    // employees.view granted, attendance.view NOT.
    $user = User::factory()->forCompany($this->company)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id,
        'module' => 'employees', 'can_view' => true,
    ]);

    $this->actingAs($user)->get("/employees/{$this->employee->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('attendanceTab', null));
});
