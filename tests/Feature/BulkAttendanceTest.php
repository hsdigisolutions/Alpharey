<?php

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserModulePermission;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
});

// ── Happy path ─────────────────────────────────────────────────────────────

it('creates attendance for all submitted employee ids', function (): void {
    $e1 = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '10']);
    $e2 = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '10']);

    $this->actingAs($this->admin)->post('/attendance/bulk', [
        'employee_ids' => [$e1->id, $e2->id],
        'date' => '2026-07-10',
        'mode' => 'hourly',
        'check_in' => '09:00',
        'check_out' => '17:00',
        'break_hours' => 1,
        'deduct_break' => true,
        'status' => 'present',
    ])->assertRedirect();

    expect(Attendance::where('date', '2026-07-10')->count())->toBe(2);
});

// ── Duplicate detection ────────────────────────────────────────────────────

it('skips employees who already have attendance on that date', function (): void {
    $e1 = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '10']);
    $e2 = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '10']);

    // Pre-create one row for e1 on that date.
    Attendance::factory()->create([
        'company_id' => $this->companyA->id,
        'employee_id' => $e1->id,
        'date' => '2026-07-15',
    ]);

    $this->actingAs($this->admin)->post('/attendance/bulk', [
        'employee_ids' => [$e1->id, $e2->id],
        'date' => '2026-07-15',
        'mode' => 'hourly',
        'check_in' => '09:00',
        'check_out' => '17:00',
        'break_hours' => 0,
        'deduct_break' => false,
        'status' => 'present',
    ])->assertRedirect();

    // Only one new row (e2) should have been created.
    expect(Attendance::where('date', '2026-07-15')->count())->toBe(2); // original e1 + new e2
    expect(Attendance::where('date', '2026-07-15')->where('employee_id', $e2->id)->exists())->toBeTrue();
});

it('creates zero rows when all employees already have attendance', function (): void {
    $e1 = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '10']);

    Attendance::factory()->create([
        'company_id' => $this->companyA->id,
        'employee_id' => $e1->id,
        'date' => '2026-07-20',
    ]);

    $this->actingAs($this->admin)->post('/attendance/bulk', [
        'employee_ids' => [$e1->id],
        'date' => '2026-07-20',
        'mode' => 'hourly',
        'check_in' => '09:00',
        'check_out' => '17:00',
        'status' => 'present',
    ])->assertRedirect();

    expect(Attendance::where('date', '2026-07-20')->count())->toBe(1); // unchanged
});

// ── Wage snapshots ─────────────────────────────────────────────────────────

it('freezes wage snapshots independently per employee', function (): void {
    $e1 = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '15']);
    $e2 = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '25']);

    $this->actingAs($this->admin)->post('/attendance/bulk', [
        'employee_ids' => [$e1->id, $e2->id],
        'date' => '2026-07-10',
        'mode' => 'hourly',
        'check_in' => '09:00',
        'check_out' => '17:00',
        'break_hours' => 0,
        'deduct_break' => false,
        'status' => 'present',
    ])->assertRedirect();

    $row1 = Attendance::where('employee_id', $e1->id)->where('date', '2026-07-10')->first();
    $row2 = Attendance::where('employee_id', $e2->id)->where('date', '2026-07-10')->first();

    expect((float) $row1->hourly_rate_snapshot)->toBe(15.0);
    expect((float) $row2->hourly_rate_snapshot)->toBe(25.0);
});

// ── Audit log ─────────────────────────────────────────────────────────────

it('writes an audit log entry for each created record', function (): void {
    $e1 = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '10']);
    $e2 = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '10']);

    $before = AuditLog::count();

    $this->actingAs($this->admin)->post('/attendance/bulk', [
        'employee_ids' => [$e1->id, $e2->id],
        'date' => '2026-07-11',
        'mode' => 'hourly',
        'check_in' => '09:00',
        'check_out' => '17:00',
        'status' => 'present',
    ])->assertRedirect();

    // Each attendance creation fires an audit row.
    expect(AuditLog::count())->toBe($before + 2);
});

// ── Permission gate ────────────────────────────────────────────────────────

it('requires attendance.create permission', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();
    $e1 = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '10']);

    $this->actingAs($user)->post('/attendance/bulk', [
        'employee_ids' => [$e1->id],
        'date' => '2026-07-10',
        'mode' => 'hourly',
        'check_in' => '09:00',
        'check_out' => '17:00',
        'status' => 'present',
    ])->assertForbidden();

    expect(Attendance::count())->toBe(0);
});

it('allows a manager with attendance.create permission to use bulk entry', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id,
        'company_id' => $this->companyA->id,
        'module' => 'attendance',
        'can_create' => true,
    ]);
    $e1 = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '10']);

    $this->actingAs($user)->post('/attendance/bulk', [
        'employee_ids' => [$e1->id],
        'date' => '2026-07-12',
        'mode' => 'hourly',
        'check_in' => '09:00',
        'check_out' => '17:00',
        'status' => 'present',
    ])->assertRedirect();

    expect(Attendance::where('date', '2026-07-12')->count())->toBe(1);
});

// ── Tenancy isolation ──────────────────────────────────────────────────────

it('ignores employee_id from another company', function (): void {
    $foreignEmployee = Employee::factory()->forCompany($this->companyB)->create();

    $this->actingAs($this->admin)->post('/attendance/bulk', [
        'employee_ids' => [$foreignEmployee->id],
        'date' => '2026-07-10',
        'mode' => 'hourly',
        'check_in' => '09:00',
        'check_out' => '17:00',
        'status' => 'present',
    ])->assertSessionHasErrors(['employee_ids.0']);

    expect(Attendance::count())->toBe(0);
});

it('does not create rows for a cross-company attack on mixed ids', function (): void {
    $own = Employee::factory()->forCompany($this->companyA)->create(['wage_type' => 'hourly', 'wage_rate' => '10']);
    $foreign = Employee::factory()->forCompany($this->companyB)->create();

    $this->actingAs($this->admin)->post('/attendance/bulk', [
        'employee_ids' => [$own->id, $foreign->id],
        'date' => '2026-07-10',
        'mode' => 'hourly',
        'check_in' => '09:00',
        'check_out' => '17:00',
        'status' => 'present',
    ])->assertSessionHasErrors();

    // Validation fails for the whole request — no rows should be created.
    expect(Attendance::count())->toBe(0);
});

// ── Index props ────────────────────────────────────────────────────────────

it('returns projectAssignments and canSeeWage in the index props', function (): void {
    $this->actingAs($this->admin)->get('/attendance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('projectAssignments')
            ->has('canSeeWage')
        );
});
