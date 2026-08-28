<?php

use App\Enums\AdvanceStatus;
use App\Enums\AttendanceStatus;
use App\Enums\BillingMethod;
use App\Enums\DeploymentStatus;
use App\Enums\UserRole;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Project;
use App\Models\ProjectEmployeeRate;
use App\Models\User;
use App\Services\Dashboard\TodayService;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'company_id' => $this->company->id,
    ]);
});

it('renders Today for a company admin', function (): void {
    $this->actingAs($this->admin)
        ->get('/today')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Today/Index')->has('data.kpis')->has('data.pending'));
});

it('ships the checked-in-now KPI, project breakdown and row distance', function (): void {
    $project = Project::factory()->forCompany($this->company)->create(['name' => 'Reforma']);
    $e = Employee::factory()->forCompany($this->company)->create();
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $e->id, 'project_id' => $project->id,
        'date' => now()->toDateString(), 'status' => 'present', 'hours_worked' => '8',
    ]);

    $this->actingAs($this->admin)->get('/today')
        ->assertInertia(fn ($page) => $page
            ->has('data.kpis.checked_in_now')
            ->has('data.project_breakdown', 1)
            ->has('data.attendance.0.distance'));
});

it('exports the filtered worker view as Excel and PDF', function (): void {
    $e = Employee::factory()->forCompany($this->company)->create();
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $e->id,
        'date' => now()->toDateString(), 'status' => 'present', 'hours_worked' => '8',
    ]);

    $this->actingAs($this->admin)->get('/today/export?format=excel')->assertOk();
    $this->actingAs($this->admin)->get('/today/export?format=pdf')->assertOk();
});

it('sends a Super Admin with no company selected to Welcome', function (): void {
    $sa = User::factory()->create(['role' => UserRole::SuperAdmin, 'company_id' => null]);

    $this->actingAs($sa)->get('/today')->assertRedirect(route('welcome'));
});

it('counts presence, leave and absence against the active headcount', function (): void {
    $today = now()->toDateString();
    // 5 active workers; 2 present, 1 on leave -> 2 absent
    $employees = Employee::factory()->count(5)->create(['company_id' => $this->company->id, 'active' => true]);

    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $employees[0]->id, 'date' => $today, 'status' => AttendanceStatus::Present]);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $employees[1]->id, 'date' => $today, 'status' => AttendanceStatus::Late]);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $employees[2]->id, 'date' => $today, 'status' => AttendanceStatus::Leave]);

    $this->actingAs($this->admin);
    $kpis = app(TodayService::class)->for($this->company->id)['kpis'];

    expect($kpis['total_workers'])->toBe(5)
        ->and($kpis['active_today'])->toBe(2)
        ->and($kpis['on_leave_today'])->toBe(1)
        ->and($kpis['absent_today'])->toBe(2);
});

function assignWorkerToProject(Project $project, Employee $employee): void
{
    $rate = new ProjectEmployeeRate(['employee_id' => $employee->id, 'wage_type' => 'daily', 'project_rate' => '50']);
    $rate->project_id = $project->id;
    $rate->company_id = $project->company_id;
    $rate->save();
}

it('shows REAL clock hours for a clerk full-day row, not the hours_worked pay field', function (): void {
    $e = Employee::factory()->forCompany($this->company)->create();
    // The production bug: a clerk-entered full day (project_based mode) carries
    // real 09:00–17:00 times but hours_worked stays 0 (only hourly mode computes it).
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $e->id,
        'date' => now()->toDateString(), 'status' => 'present',
        'mode' => 'project_based', 'day_type' => 'full',
        'check_in' => '09:00', 'check_out' => '17:00', 'hours_worked' => '0',
    ]);

    $this->actingAs($this->admin);
    $data = app(TodayService::class)->for($this->company->id);

    expect($data['attendance'][0]['hours'])->toBe(8.0)
        ->and($data['attendance'][0]['still_working'])->toBeFalse()
        ->and($data['kpis']['hours_today'])->toBe(8.0);
});

it('shows real hours for half-day and hourly rows from the clock', function (): void {
    $half = Employee::factory()->forCompany($this->company)->create();
    $hourly = Employee::factory()->forCompany($this->company)->create();
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $half->id, 'date' => now()->toDateString(),
        'status' => 'present', 'mode' => 'project_based', 'day_type' => 'half',
        'check_in' => '09:00', 'check_out' => '13:00', 'hours_worked' => '0',
    ]);
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $hourly->id, 'date' => now()->toDateString(),
        'status' => 'present', 'mode' => 'hourly', 'day_type' => 'hourly',
        'check_in' => '09:00', 'check_out' => '14:30', 'hours_worked' => '5.5',
    ]);

    $this->actingAs($this->admin);
    $hours = collect(app(TodayService::class)->for($this->company->id)['attendance'])->pluck('hours')->all();

    expect($hours)->toContain(4.0)->toContain(5.5);
});

it('marks an open shift as still working with hours elapsed so far', function (): void {
    $e = Employee::factory()->forCompany($this->company)->create();
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => now()->toDateString(),
        'status' => 'present', 'check_in' => '09:00', 'check_out' => null,
        'check_in_at' => now()->subHours(3), 'check_out_at' => null, 'hours_worked' => '0',
    ]);

    $this->actingAs($this->admin);
    $row = app(TodayService::class)->for($this->company->id)['attendance'][0];

    expect($row['still_working'])->toBeTrue()
        ->and($row['hours'])->toBeGreaterThan(2.5);
});

it('never shows hours for an absent row with stale check-in/out times', function (): void {
    $present = Employee::factory()->forCompany($this->company)->create();
    $absent = Employee::factory()->forCompany($this->company)->create();

    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $present->id, 'date' => now()->toDateString(),
        'status' => 'present', 'mode' => 'project_based', 'day_type' => 'full',
        'check_in' => '09:00', 'check_out' => '17:00', 'hours_worked' => '0',
    ]);
    // Absent, but carries leftover 09:00–17:00 times (was present, then changed).
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $absent->id, 'date' => now()->toDateString(),
        'status' => 'absent', 'check_in' => '09:00', 'check_out' => '17:00', 'hours_worked' => '0',
    ]);

    $this->actingAs($this->admin);
    $data = app(TodayService::class)->for($this->company->id);

    $absentRow = collect($data['attendance'])->firstWhere('status', 'absent');
    expect($absentRow['hours'])->toBe(0.0)
        ->and($absentRow['worked'])->toBeFalse();
    // Only the present worker's 8h counts toward the KPI — the absent row is out.
    expect($data['kpis']['hours_today'])->toBe(8.0);
});

it('lists active, staffed projects with nobody working today (excludes the rest)', function (): void {
    $today = now()->toDateString();
    $worker = Employee::factory()->forCompany($this->company)->create();

    // A — active, staffed, NO activity today, last worked 3 days ago → shown.
    $a = Project::factory()->forCompany($this->company)->create(['name' => 'Villa', 'status' => 'active']);
    assignWorkerToProject($a, $worker);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $worker->id, 'project_id' => $a->id, 'date' => now()->subDays(3)->toDateString(), 'status' => 'present']);

    // B — active, staffed, HAS activity today → excluded.
    $b = Project::factory()->forCompany($this->company)->create(['name' => 'Edificio', 'status' => 'in_progress']);
    assignWorkerToProject($b, $worker);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $worker->id, 'project_id' => $b->id, 'date' => $today, 'status' => 'present']);

    // C — active, NO assigned workers → excluded (staffing issue, not this).
    Project::factory()->forCompany($this->company)->create(['name' => 'Solar', 'status' => 'active']);

    // D — completed, staffed, no activity → excluded (not active).
    $d = Project::factory()->forCompany($this->company)->create(['name' => 'Antiguo', 'status' => 'completed']);
    assignWorkerToProject($d, $worker);

    $this->actingAs($this->admin);
    $data = app(TodayService::class)->for($this->company->id);
    $list = collect($data['projects_no_activity']);

    expect($list->pluck('project')->all())
        ->toContain('Villa')->not->toContain('Edificio')->not->toContain('Solar')->not->toContain('Antiguo');
    $villa = $list->firstWhere('project', 'Villa');
    expect($villa['assigned'])->toBe(1)
        ->and($villa['last_activity'])->toBe(now()->subDays(3)->toDateString());
    // KPI count matches the list length (Villa only).
    expect($data['kpis']['projects_no_activity'])->toBe(1);
});

it('treats recent attendance (last 30 days) as staffing, even with no rate roster', function (): void {
    $worker = Employee::factory()->forCompany($this->company)->create();

    // No rate roster, but someone worked here 5 days ago → staffed → shown.
    $recent = Project::factory()->forCompany($this->company)->create(['name' => 'Reciente', 'status' => 'active']);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $worker->id, 'project_id' => $recent->id, 'date' => now()->subDays(5)->toDateString(), 'status' => 'present']);

    // No roster, last worked 40 days ago → outside the window → NOT staffed → hidden.
    $stale = Project::factory()->forCompany($this->company)->create(['name' => 'Vieja', 'status' => 'active']);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $worker->id, 'project_id' => $stale->id, 'date' => now()->subDays(40)->toDateString(), 'status' => 'present']);

    $this->actingAs($this->admin);
    $list = collect(app(TodayService::class)->for($this->company->id)['projects_no_activity']);

    $reciente = $list->firstWhere('project', 'Reciente');
    expect($reciente)->not->toBeNull()
        ->and($reciente['assigned'])->toBe(1)
        ->and($reciente['last_activity'])->toBe(now()->subDays(5)->toDateString());
    expect($list->pluck('project')->all())->not->toContain('Vieja');
});

it('shows Never when a staffed active project has no past attendance', function (): void {
    $worker = Employee::factory()->forCompany($this->company)->create();
    $p = Project::factory()->forCompany($this->company)->create(['name' => 'Nueva', 'status' => 'active']);
    assignWorkerToProject($p, $worker);

    $this->actingAs($this->admin);
    $row = collect(app(TodayService::class)->for($this->company->id)['projects_no_activity'])->firstWhere('project', 'Nueva');

    expect($row)->not->toBeNull()
        ->and($row['last_activity'])->toBeNull()
        ->and($row['days_ago'])->toBeNull();
});

it('shows the home company for a worker deployed into us', function (): void {
    $home = Company::factory()->create(['name' => 'Empresa Origen']);
    $employee = Employee::factory()->create(['company_id' => $home->id]);
    $project = Project::factory()->create(['company_id' => $this->company->id]);

    // Attendance is logged under the HOST company for a deployed worker.
    Attendance::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'project_id' => $project->id,
        'date' => now()->toDateString(),
        'status' => AttendanceStatus::Present,
    ]);

    EmployeeDeployment::query()->create([
        'employee_id' => $employee->id,
        'home_company_id' => $home->id,
        'host_company_id' => $this->company->id,
        'project_id' => $project->id,
        'deployment_start' => now()->subDay()->toDateString(),
        'billing_method' => BillingMethod::OptionA,
        'status' => DeploymentStatus::Active,
    ]);

    $this->actingAs($this->admin);
    $rows = app(TodayService::class)->for($this->company->id)['attendance'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['home_company'])->toBe('Empresa Origen');
});

it('lists advances pending approval with the amount for a pay-viewer', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $advance = new Advance([
        'employee_id' => $employee->id,
        'amount' => '250',
        'status' => AdvanceStatus::Pending->value,
        'request_date' => now()->toDateString(),
    ]);
    $advance->company_id = $this->company->id;
    $advance->save();

    // A Company Admin has payroll.view via Gate::before, so the amount is present.
    $this->actingAs($this->admin)
        ->get('/today')
        ->assertInertia(fn ($page) => $page
            ->where('can.view_pay', true)
            ->where('data.pending.advances_pending.0.amount', fn ($amount) => (float) $amount === 250.0));
});

it('strips the advance amount for a user without pay permission', function (): void {
    $plain = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);
    // grant only leave.view so the page is reachable is not needed — today has no
    // module gate; but the pay figure must be hidden.
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $advance = new Advance([
        'employee_id' => $employee->id,
        'amount' => '250',
        'status' => AdvanceStatus::Pending->value,
        'request_date' => now()->toDateString(),
    ]);
    $advance->company_id = $this->company->id;
    $advance->save();

    $this->actingAs($plain)
        ->get('/today')
        ->assertInertia(fn ($page) => $page
            ->where('can.view_pay', false)
            ->where('data.pending.advances_pending', fn ($rows) => ! array_key_exists('amount', $rows[0])));
});

it('filters the attendance list by search, project and status', function (): void {
    $today = now()->toDateString();
    $alpha = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Carlos García']);
    $beta = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Ana López']);
    $projectA = Project::factory()->create(['company_id' => $this->company->id, 'name' => 'Obra Norte']);
    $projectB = Project::factory()->create(['company_id' => $this->company->id, 'name' => 'Obra Sur']);

    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $alpha->id, 'project_id' => $projectA->id, 'date' => $today, 'status' => AttendanceStatus::Present]);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $beta->id, 'project_id' => $projectB->id, 'date' => $today, 'status' => AttendanceStatus::Absent]);

    $this->actingAs($this->admin);
    $service = app(TodayService::class);

    // Unfiltered: both rows, total 2, both projects offered.
    $all = $service->for($this->company->id);
    expect($all['attendance'])->toHaveCount(2)
        ->and($all['attendance_total'])->toBe(2)
        ->and($all['filter_options']['projects'])->toHaveCount(2);

    // Search by name.
    expect($service->for($this->company->id, ['search' => 'carlos'])['attendance'])->toHaveCount(1);

    // Filter by project.
    $byProject = $service->for($this->company->id, ['project' => $projectA->id]);
    expect($byProject['attendance'])->toHaveCount(1)
        ->and($byProject['attendance'][0]['employee'])->toBe('Carlos García')
        ->and($byProject['attendance_total'])->toBe(2); // total stays unfiltered

    // Filter by status (multi-select — one ticked).
    expect($service->for($this->company->id, ['statuses' => [AttendanceStatus::Absent->value]])['attendance'])->toHaveCount(1);
});

it('honours a date range and flags a single day', function (): void {
    $e = Employee::factory()->create(['company_id' => $this->company->id]);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => '2026-07-10', 'status' => 'present', 'hours_worked' => '8']);
    Attendance::factory()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => '2026-07-11', 'status' => 'absent']);

    $this->actingAs($this->admin);
    $service = app(TodayService::class);

    $range = $service->for($this->company->id, ['from' => '2026-07-10', 'to' => '2026-07-11']);
    expect($range['attendance'])->toHaveCount(2)
        ->and($range['single_day'])->toBeFalse();

    $oneDay = $service->for($this->company->id, ['from' => '2026-07-10', 'to' => '2026-07-10']);
    expect($oneDay['attendance'])->toHaveCount(1)
        ->and($oneDay['single_day'])->toBeTrue();
});

it('denies Today to a guest', function (): void {
    $this->get('/today')->assertRedirect(route('login'));
});
