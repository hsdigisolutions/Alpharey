<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Subcontractor;
use App\Models\SubcontractorPayment;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\Reports\ProfitabilityService;

/**
 * Project profitability (P&L). Revenue − cost per project, by billing method,
 * with the outsourced + subcontractor variations. All figures are server-side.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create();
    $this->service = app(ProfitabilityService::class);
});

/** Book an attendance row with an explicit labour total (bypasses the service). */
function punch(Company $company, Employee $employee, Project $project, string $date, float $hours, float $labour, string $dayType = 'hourly', float $quantity = 0): void
{
    Attendance::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'project_id' => $project->id,
        'date' => $date,
        'status' => 'present',
        'day_type' => $dayType,
        'hours_worked' => (string) $hours,
        'quantity' => (string) $quantity,
        'total_amount' => (string) $labour,
    ]);
}

it('computes an hourly project P&L (revenue = client rate × hours)', function (): void {
    $project = Project::factory()->create([
        'company_id' => $this->company->id, 'billing_type' => 'hourly', 'client_hour_rate' => '20',
    ]);
    // 100 h of work costing 1.450 € (avg 14,50/h).
    punch($this->company, $this->employee, $project, '2026-06-01', 50, 725);
    punch($this->company, $this->employee, $project, '2026-06-02', 50, 725);
    Expense::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id,
        'approved' => true, 'total' => '50', 'date' => '2026-06-02',
    ]);

    $r = $this->service->forProject($project->fresh());

    expect($r['revenue'])->toBe(2000.0)          // 20 × 100
        ->and($r['labour_cost'])->toBe(1450.0)
        ->and($r['expenses'])->toBe(50.0)
        ->and($r['cost'])->toBe(1500.0)          // 1450 + 50
        ->and($r['profit'])->toBe(500.0)         // 2000 − 1500
        ->and($r['margin'])->toBe(25.0)          // 500 / 2000
        ->and($r['avg_cost_per_hour'])->toBe(14.5)
        ->and($r['margin_per_hour'])->toBe(5.5)  // 20 − 14,5
        ->and($r['health'])->toBe('ok');
});

it('computes a per-meter project P&L (revenue = client rate × metres)', function (): void {
    $project = Project::factory()->create([
        'company_id' => $this->company->id, 'billing_type' => 'per_meter', 'client_meter_rate' => '30',
    ]);
    punch($this->company, $this->employee, $project, '2026-06-01', 0, 2000, 'per_meter', 100);

    $r = $this->service->forProject($project->fresh());

    expect($r['revenue'])->toBe(3000.0)   // 30 × 100 m
        ->and($r['meters'])->toBe(100.0)
        ->and($r['cost'])->toBe(2000.0)
        ->and($r['profit'])->toBe(1000.0);
});

it('bills a fixed project from its paid sale invoices', function (): void {
    $project = Project::factory()->create([
        'company_id' => $this->company->id, 'billing_type' => 'fixed',
    ]);
    punch($this->company, $this->employee, $project, '2026-06-01', 100, 4000);
    Invoice::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id, 'type' => 'sale',
        'payment_status' => 'paid', 'total' => '5000', 'invoice_date' => '2026-06-10',
    ]);
    // An UNPAID invoice must not count towards revenue.
    Invoice::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id, 'type' => 'sale',
        'payment_status' => 'unpaid', 'total' => '9999', 'invoice_date' => '2026-06-11',
    ]);

    $r = $this->service->forProject($project->fresh());

    expect($r['revenue'])->toBe(5000.0)
        ->and($r['labour_cost'])->toBe(4000.0)
        ->and($r['profit'])->toBe(1000.0)
        ->and($r['margin'])->toBe(20.0);
});

it('costs an outsourced project by its flat fee, not our attendance', function (): void {
    $project = Project::factory()->create([
        'company_id' => $this->company->id, 'billing_type' => 'fixed',
        'outsourced' => true, 'outsource_cost' => '3000',
    ]);
    // Attendance labour is IGNORED for an outsourced project.
    punch($this->company, $this->employee, $project, '2026-06-01', 100, 9999);
    Invoice::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id, 'type' => 'sale',
        'payment_status' => 'paid', 'total' => '5000', 'invoice_date' => '2026-06-10',
    ]);
    Expense::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id,
        'approved' => true, 'total' => '500', 'date' => '2026-06-05',
    ]);

    $r = $this->service->forProject($project->fresh());

    expect($r['labour_cost'])->toBe(3000.0)   // the outsource fee, not 9.999
        ->and($r['cost'])->toBe(3500.0)       // 3000 + 500
        ->and($r['profit'])->toBe(1500.0);
});

it('includes paid subcontractor payments in the cost', function (): void {
    $project = Project::factory()->create([
        'company_id' => $this->company->id, 'billing_type' => 'hourly', 'client_hour_rate' => '20',
    ]);
    punch($this->company, $this->employee, $project, '2026-06-01', 100, 1000);

    $sub = new Subcontractor(['project_id' => $project->id, 'name' => 'Thaekedar SL', 'status' => 'active']);
    $sub->company_id = $this->company->id;
    $sub->save();
    $paid = new SubcontractorPayment(['payment_number' => 1, 'payment_date' => '2026-06-03', 'amount' => '800', 'status' => 'paid']);
    $paid->subcontractor_id = $sub->id;
    $paid->save();
    $pending = new SubcontractorPayment(['payment_number' => 2, 'payment_date' => '2026-06-04', 'amount' => '500', 'status' => 'pending']);
    $pending->subcontractor_id = $sub->id;
    $pending->save();

    $r = $this->service->forProject($project->fresh());

    expect($r['subcontractor_cost'])->toBe(800.0)   // pending is excluded
        ->and($r['cost'])->toBe(1800.0)             // 1000 labour + 800 sub
        ->and($r['revenue'])->toBe(2000.0)
        ->and($r['profit'])->toBe(200.0);
});

it('classifies the margin into the traffic light', function (): void {
    $green = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'hourly', 'client_hour_rate' => '20']);
    punch($this->company, $this->employee, $green, '2026-06-01', 100, 1450); // margin 27,5 → ok

    $amber = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'hourly', 'client_hour_rate' => '10']);
    punch($this->company, $this->employee, $amber, '2026-06-02', 100, 900);  // rev 1000, margin 10 → warn

    $red = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'hourly', 'client_hour_rate' => '10']);
    punch($this->company, $this->employee, $red, '2026-06-03', 100, 990);    // rev 1000, margin 1 → danger

    expect($this->service->forProject($green->fresh())['health'])->toBe('ok')
        ->and($this->service->forProject($amber->fresh())['health'])->toBe('warn')
        ->and($this->service->forProject($red->fresh())['health'])->toBe('danger');
});

it('tallies the dashboard buckets and ignores other companies', function (): void {
    $ok = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'hourly', 'client_hour_rate' => '20']);
    punch($this->company, $this->employee, $ok, '2026-06-01', 100, 1450);
    $loss = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'hourly', 'client_hour_rate' => '10']);
    punch($this->company, $this->employee, $loss, '2026-06-02', 100, 1200); // rev 1000 < cost → loss

    // Another company's loss-making project must not leak into our counts.
    $other = Company::factory()->create();
    $otherEmp = Employee::factory()->forCompany($other)->create();
    $otherProj = Project::factory()->create(['company_id' => $other->id, 'billing_type' => 'hourly', 'client_hour_rate' => '10']);
    punch($other, $otherEmp, $otherProj, '2026-06-01', 100, 5000);

    $counts = $this->service->dashboardCounts($this->company->id);

    expect($counts['profitable'])->toBe(1)
        ->and($counts['loss'])->toBe(1)
        ->and($counts['at_risk'])->toBe(0);
});

it('produces day and month breakdowns for a selected project', function (): void {
    $project = Project::factory()->create([
        'company_id' => $this->company->id, 'billing_type' => 'hourly', 'client_hour_rate' => '20',
    ]);
    punch($this->company, $this->employee, $project, '2026-06-03', 8, 116); // 160 rev, 44 profit
    punch($this->company, $this->employee, $project, '2026-07-04', 7, 101.5);

    $detail = $this->service->forProject($project->fresh(), null, null, true);

    expect($detail['day_breakdown'])->toHaveCount(2)
        ->and($detail['day_breakdown'][0]['revenue'])->toBe(160.0)
        ->and($detail['day_breakdown'][0]['profit'])->toBe(44.0)
        ->and($detail['month_breakdown'])->toHaveCount(2)
        ->and($detail['month_breakdown'][0]['month'])->toBe('2026-06');
});

it('blocks the profitability report for a user who cannot view payroll', function (): void {
    $manager = User::factory()->create(['role' => 'manager', 'company_id' => $this->company->id]);
    UserModulePermission::query()->create([
        'user_id' => $manager->id, 'company_id' => $this->company->id,
        'module' => 'reports', 'can_view' => true, 'can_export' => true,
    ]);

    $this->actingAs($manager)
        ->get('/reports?module=profitability')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('blocked', true)->where('report', null));

    $this->actingAs($manager)->get('/reports/export/pdf?module=profitability')->assertForbidden();
});

it('serves the profitability report to an admin with the project rows', function (): void {
    $admin = User::factory()->create(['role' => 'admin', 'company_id' => $this->company->id]);
    $project = Project::factory()->create(['company_id' => $this->company->id, 'billing_type' => 'hourly', 'client_hour_rate' => '20']);
    punch($this->company, $this->employee, $project, '2026-06-01', 100, 1450);

    $this->actingAs($admin)
        ->get('/reports?module=profitability')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('blocked', false)
            ->where('report.figures.total_revenue', 2000)
            ->has('report.rows', 1));
});
