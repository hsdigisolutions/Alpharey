<?php

use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\Reports\ReportService;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'company_id' => $this->company->id,
    ]);
});

it('renders the reports page with the employees module by default', function (): void {
    $this->actingAs($this->admin)
        ->get('/reports')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reports/Index')->where('module', 'employees')->has('report.figures'));
});

it('sends a Super Admin with no company selected to Welcome', function (): void {
    $sa = User::factory()->create(['role' => UserRole::SuperAdmin, 'company_id' => null]);

    $this->actingAs($sa)->get('/reports')->assertRedirect(route('welcome'));
});

it('counts employees for the active company only', function (): void {
    Employee::factory()->count(3)->create(['company_id' => $this->company->id, 'active' => true]);
    Employee::factory()->create(['company_id' => $this->company->id, 'active' => false]);
    Employee::factory()->count(9)->create(['company_id' => Company::factory()->create()->id]);

    $this->actingAs($this->admin);
    $report = app(ReportService::class)->for('employees', []);

    expect($report['figures']['total'])->toBe(4)
        ->and($report['figures']['active'])->toBe(3)
        ->and($report['figures']['inactive'])->toBe(1);
});

it('reports NET worked hours: a clerk full 08:00-17:00 day counts as 8 h, not 0 or 9', function (): void {
    $full = Employee::factory()->forCompany($this->company)->create();
    $hourly = Employee::factory()->forCompany($this->company)->create();

    // Clerk full day: project_based, hours_worked stays 0, but the 08:00–17:00
    // span nets to 8 h after the 1 h break.
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $full->id,
        'date' => now()->toDateString(), 'status' => 'present', 'mode' => 'project_based',
        'day_type' => 'full', 'check_in' => '08:00', 'check_out' => '17:00', 'hours_worked' => '0',
    ]);
    // Hourly day: 5.5 recorded hours shown verbatim.
    Attendance::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $hourly->id,
        'date' => now()->toDateString(), 'status' => 'present', 'mode' => 'hourly',
        'day_type' => 'hourly', 'check_in' => '09:00', 'check_out' => '14:30', 'hours_worked' => '5.5',
    ]);

    $this->actingAs($this->admin);
    $report = app(ReportService::class)->for('attendance', []);

    expect($report['figures']['total_hours'])->toBe(13.5); // 8 + 5.5, not 9 + 5.5
});

it('computes the financial net position from sales minus expenses', function (): void {
    Invoice::factory()->create([
        'company_id' => $this->company->id, 'type' => InvoiceType::Sale,
        'invoice_date' => now()->toDateString(), 'total' => '1000',
    ]);
    Invoice::factory()->create([
        'company_id' => $this->company->id, 'type' => InvoiceType::Expense,
        'invoice_date' => now()->toDateString(), 'total' => '300',
    ]);

    $this->actingAs($this->admin);
    $report = app(ReportService::class)->for('financial', ['from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString()]);

    expect($report['figures']['sales_total'])->toBe(1000.0)
        ->and($report['figures']['expense_total'])->toBe(300.0)
        ->and($report['figures']['net_position'])->toBe(700.0);
});

/**
 * SECURITY: reports.view is not enough for a money report — the payroll and
 * financial modules need their own view right too.
 */
it('blocks the payroll report for a user who cannot view payroll', function (): void {
    $user = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id,
        'module' => 'reports', 'can_view' => true, 'can_export' => true,
    ]);

    // The page renders but the payroll report is withheld.
    $this->actingAs($user)
        ->get('/reports?module=payroll')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('blocked', true)->where('report', null));

    // And the export is refused outright.
    $this->actingAs($user)->get('/reports/export/pdf?module=payroll')->assertForbidden();
});

it('offers a payroll-less module list to a user without payroll rights', function (): void {
    $user = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id,
        'module' => 'reports', 'can_view' => true,
    ]);

    $this->actingAs($user)
        ->get('/reports')
        ->assertInertia(fn ($page) => $page->where('modules', fn ($mods) => ! collect($mods)->contains('payroll') && collect($mods)->contains('employees')));
});

it('exports the filtered view as excel', function (): void {
    Employee::factory()->create(['company_id' => $this->company->id]);

    $response = $this->actingAs($this->admin)->get('/reports/export/excel?module=employees')->assertOk();

    expect($response->headers->get('content-type'))
        ->toContain('spreadsheetml.sheet');
});

it('denies reports to a guest', function (): void {
    $this->get('/reports')->assertRedirect(route('login'));
});
