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

it('denies Today to a guest', function (): void {
    $this->get('/today')->assertRedirect(route('login'));
});
