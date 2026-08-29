<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
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

it('shows all employees on a project in the project view over a custom range', function (): void {
    $project = Project::factory()->create(['company_id' => $this->company->id]);
    $e2 = Employee::factory()->forCompany($this->company)->create();
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'project_id' => $project->id, 'date' => '2026-08-10', 'status' => 'present', 'hours_worked' => '8']);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $e2->id, 'project_id' => $project->id, 'date' => '2026-08-11', 'status' => 'present', 'hours_worked' => '6']);

    $this->actingAs($this->admin)
        ->get("/timesheet?view=project&project={$project->id}&mode=custom&from=2026-08-10&to=2026-08-11")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Timesheet/Index')
            ->has('sheet.rows', 2)
            ->where('sheet.workers', 2)
            ->where('sheet.total_hours', fn ($v): bool => (float) $v === 14.0));
});

it('ships per-worker worked dates that reconcile to the summary, and exports both formats', function (): void {
    $project = Project::factory()->create(['company_id' => $this->company->id]);
    $w1 = Employee::factory()->forCompany($this->company)->create();
    $w2 = Employee::factory()->forCompany($this->company)->create();

    // w1: two worked days (full 8h + half 4h) and one ABSENT day (excluded).
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $w1->id, 'project_id' => $project->id, 'date' => '2026-08-03', 'status' => 'present', 'day_type' => 'full', 'hours_worked' => '8']);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $w1->id, 'project_id' => $project->id, 'date' => '2026-08-05', 'status' => 'present', 'day_type' => 'half', 'hours_worked' => '4']);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $w1->id, 'project_id' => $project->id, 'date' => '2026-08-06', 'status' => 'absent', 'hours_worked' => '0']);
    // w2: one worked day (hourly 5.5h).
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $w2->id, 'project_id' => $project->id, 'date' => '2026-08-04', 'status' => 'present', 'day_type' => 'hourly', 'hours_worked' => '5.5']);

    $q = "view=project&project={$project->id}&mode=custom&from=2026-08-01&to=2026-08-31";

    $res = $this->actingAs($this->admin)->get("/timesheet?{$q}")->assertOk();
    $sheet = $res->viewData('page')['props']['sheet'];

    // Reconciliation: each worker's day hours sum to their total; workers sum to grand total.
    $grand = 0.0;
    foreach ($sheet['rows'] as $row) {
        $daySum = round(array_sum(array_column($row['days'], 'hours')), 2);
        expect($daySum)->toBe((float) $row['hours']);
        expect(count($row['days']))->toBe($row['days_present']);
        $grand += (float) $row['hours'];
    }
    expect(round($grand, 2))->toBe((float) $sheet['total_hours'])
        ->and((float) $sheet['total_hours'])->toBe(17.5) // 8 + 4 + 5.5
        ->and($sheet['total_days'])->toBe(3);

    // The detail carries the localized day type + weekday (absent day excluded).
    $w1row = collect($sheet['rows'])->firstWhere('employee_id', $w1->id);
    expect($w1row['days'])->toHaveCount(2)
        ->and($w1row['days'][0]['day_type_label'])->toBe('Jornada completa')
        ->and($w1row['days'][1]['day_type_label'])->toBe('Media jornada')
        ->and($w1row['days'][0]['weekday'])->not->toBe('');

    // Both export formats work and are audited.
    $this->actingAs($this->admin)->get("/timesheet/export?{$q}&format=excel")->assertOk();
    $this->actingAs($this->admin)->get("/timesheet/export?{$q}&format=pdf")->assertOk();
    $this->assertDatabaseHas('audit_logs', ['action' => 'exported', 'module' => 'attendance']);
});

it('denies the timesheet without attendance.view', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->get('/timesheet')->assertForbidden();
});
