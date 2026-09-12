<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\ProfitabilityService;

/**
 * Item 4 — project P&L splits approved expenses by who bears them. Company-side
 * (company/employee/unbillable) is always our cost. Client-billable is a
 * reimbursed pass-through: excluded from cost on UNIT-billed projects (revenue
 * doesn't include it), kept in cost on INVOICE-billed projects (the paid invoice
 * already includes it — netting, no double count).
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($this->admin);
});

function labourDay(Company $company, Project $project): void
{
    Attendance::factory()->create([
        'company_id' => $company->id,
        'employee_id' => Employee::factory()->forCompany($company)->create()->id,
        'project_id' => $project->id, 'date' => '2026-06-01', 'status' => 'present',
        'day_type' => 'hourly', 'hours_worked' => '8', 'total_amount' => '100',
    ]);
}

function projectExpense(Company $company, Project $project, string $total, string $bearer): void
{
    Expense::factory()->create([
        'company_id' => $company->id, 'project_id' => $project->id, 'approved' => true,
        'total' => $total, 'bearable_by' => $bearer, 'date' => '2026-06-01', 'type' => 'other',
    ]);
}

it('excludes client-billable expenses from cost on a UNIT-billed project (recoverable)', function (): void {
    $project = Project::factory()->forCompany($this->company)->create(['billing_type' => 'hourly', 'client_hour_rate' => '20']);
    labourDay($this->company, $project);
    projectExpense($this->company, $project, '30', 'company'); // our cost
    projectExpense($this->company, $project, '10', 'client');  // recoverable

    $pnl = app(ProfitabilityService::class)->forProject($project->fresh());

    // revenue 8×20=160; cost = labour 100 + operational 30 (client 10 excluded).
    expect($pnl['revenue'])->toBe(160.0)
        ->and($pnl['expenses'])->toBe(30.0)
        ->and($pnl['cost'])->toBe(130.0)
        ->and($pnl['profit'])->toBe(30.0);
    expect($pnl['expense_breakdown']['operational']['total'])->toBe(30.0)
        ->and($pnl['expense_breakdown']['client_billable']['total'])->toBe(10.0)
        ->and($pnl['expense_breakdown']['client_billable']['in_cost'])->toBeFalse();
});

it('keeps client-billable expenses in cost on an INVOICE-billed project (nets the invoice)', function (): void {
    $project = Project::factory()->forCompany($this->company)->create(['billing_type' => 'fixed']);
    labourDay($this->company, $project);
    projectExpense($this->company, $project, '30', 'company');
    projectExpense($this->company, $project, '10', 'client');

    $pnl = app(ProfitabilityService::class)->forProject($project->fresh());

    // fixed → revenue = paid invoices (0). cost = labour 100 + operational 30 + client 10 = 140.
    expect($pnl['expenses'])->toBe(40.0)
        ->and($pnl['cost'])->toBe(140.0)
        ->and($pnl['expense_breakdown']['client_billable']['in_cost'])->toBeTrue();
});

it('leaves company-bearable expenses fully as cost everywhere (control group)', function (): void {
    $project = Project::factory()->forCompany($this->company)->create(['billing_type' => 'hourly', 'client_hour_rate' => '20']);
    labourDay($this->company, $project);
    projectExpense($this->company, $project, '50', 'company');

    $pnl = app(ProfitabilityService::class)->forProject($project->fresh());

    expect($pnl['expenses'])->toBe(50.0)
        ->and($pnl['cost'])->toBe(150.0)
        ->and($pnl['profit'])->toBe(10.0) // 160 − 150
        ->and($pnl['expense_breakdown']['client_billable']['total'])->toBe(0.0);
});

it('splits the daily-view expenses the same way (unit-billed excludes client)', function (): void {
    $project = Project::factory()->forCompany($this->company)->create(['billing_type' => 'hourly', 'client_hour_rate' => '20']);
    labourDay($this->company, $project);
    projectExpense($this->company, $project, '30', 'company');
    projectExpense($this->company, $project, '10', 'client');

    $pnl = app(ProfitabilityService::class)->dailyPnl($project->fresh());
    $day = collect($pnl['days'])->firstWhere('date', '2026-06-01');

    // Day cost = labour 100 + operational 30 only; income 160 → profit 30.
    expect((float) $day['expenses'])->toBe(30.0)
        ->and((float) $day['profit'])->toBe(30.0);
    expect($pnl['expense_breakdown']['client_billable']['total'])->toBe(10.0)
        ->and($pnl['expense_breakdown']['client_billable']['in_cost'])->toBeFalse();
});
