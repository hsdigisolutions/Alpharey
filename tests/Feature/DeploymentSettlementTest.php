<?php

use App\Enums\AttendanceStatus;
use App\Enums\BillingMethod;
use App\Enums\DeploymentRateType;
use App\Enums\DeploymentStatus;
use App\Enums\ExpenseType;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\DeploymentCharge;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Services\Deployments\DeploymentChargeService;
use App\Services\Reports\ProfitabilityService;

/**
 * Deployment settlement (2026-09, invoice cascade 2026-09-12). The HOST marks the
 * cross-charge paid once it reimburses the HOME company. As of the invoice
 * redesign the settlement is a CASCADE that keeps three records in lockstep
 * (host Expense.approved, home Invoice paid, DeploymentCharge). The load-bearing
 * guarantee moved: Item A's double-count protection is now STRUCTURAL — the
 * internal_deployment type is excluded from project P&L (ProfitabilityService),
 * so approving the expense as part of settlement adds nothing to project cost.
 */
beforeEach(function (): void {
    $this->home = Company::factory()->create(['name' => 'Shizukani']);
    $this->host = Company::factory()->create(['name' => 'Alovar']);
    $this->hostAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]);
    $this->homeAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->home->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->home->id, 'daily_wage' => '100']);
    $this->project = Project::factory()->create(['company_id' => $this->host->id]);
});

/** An ACTIVE Option-A deployment with `$days` of host-project attendance. */
function settleDeployment(int $days = 3, float $rate = 100.0): EmployeeDeployment
{
    $start = now()->startOfMonth();
    $deployment = EmployeeDeployment::query()->create([
        'employee_id' => test()->employee->id,
        'home_company_id' => test()->home->id,
        'host_company_id' => test()->host->id,
        'project_id' => test()->project->id,
        'deployment_start' => $start->toDateString(),
        'deployment_end' => $start->copy()->addDays($days - 1)->toDateString(),
        'billing_method' => BillingMethod::OptionA,
        'rate_during_deployment' => (string) $rate,
        'rate_type' => DeploymentRateType::Daily,
        'split_pct' => '100',
        'status' => DeploymentStatus::Active,
    ]);
    for ($i = 0; $i < $days; $i++) {
        Attendance::factory()->create([
            'company_id' => test()->host->id, 'employee_id' => test()->employee->id,
            'project_id' => test()->project->id, 'date' => $start->copy()->addDays($i)->toDateString(),
            'status' => AttendanceStatus::Present, 'total_amount' => (string) $rate,
        ]);
    }

    return $deployment;
}

function chargeFor(EmployeeDeployment $d): ?DeploymentCharge
{
    return DeploymentCharge::query()->where('employee_deployment_id', $d->id)->first();
}

it('stamps the charge unpaid + invoiced_at on completion (and it is not payable while active)', function (): void {
    $d = settleDeployment(3, 100.0);

    // Active → no invoiced_at yet, so nothing is settleable.
    $this->actingAs($this->hostAdmin)->post("/deployments/{$d->id}/settlement", ['paid' => true])
        ->assertStatus(422);

    // Complete → the charge locks, invoiced_at is stamped, settlement is unpaid.
    app(DeploymentChargeService::class)->generateCharge($d->fresh());

    $charge = chargeFor($d);
    expect($charge->settlement_status)->toBe('unpaid')
        ->and($charge->invoiced_at)->not->toBeNull()
        ->and((float) $charge->amount)->toBe(300.0);
});

it('lets the HOST mark the charge paid (paid_at + paid_by stamped)', function (): void {
    $d = settleDeployment(2, 100.0);
    app(DeploymentChargeService::class)->generateCharge($d->fresh());

    $this->actingAs($this->hostAdmin)->post("/deployments/{$d->id}/settlement", ['paid' => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    $charge = chargeFor($d);
    expect($charge->settlement_status)->toBe('paid')
        ->and($charge->paid_at)->not->toBeNull()
        ->and($charge->paid_by)->toBe($this->hostAdmin->id);

    // …and can un-mark it (correction).
    $this->actingAs($this->hostAdmin)->post("/deployments/{$d->id}/settlement", ['paid' => false])->assertRedirect();
    expect(chargeFor($d)->fresh()->settlement_status)->toBe('unpaid');
});

it('forbids the HOME company from marking it paid (host-only action)', function (): void {
    $d = settleDeployment(2, 100.0);
    app(DeploymentChargeService::class)->generateCharge($d->fresh());

    $this->actingAs($this->homeAdmin)->post("/deployments/{$d->id}/settlement", ['paid' => true])
        ->assertStatus(403);

    expect(chargeFor($d)->settlement_status)->toBe('unpaid');
});

it('marking paid now approves the host expense in lockstep, and un-marking reverts it', function (): void {
    $d = settleDeployment(3, 100.0);
    app(DeploymentChargeService::class)->generateCharge($d->fresh());

    $expense = Expense::query()->withoutGlobalScope(CompanyScope::class)
        ->where('type', ExpenseType::InternalDeployment->value)->firstOrFail();
    expect($expense->approved)->toBeFalse();

    // Mark paid → the host expense is approved as part of the single cascade.
    $this->actingAs($this->hostAdmin)->post("/deployments/{$d->id}/settlement", ['paid' => true])->assertRedirect();
    $expense->refresh();
    expect($expense->approved)->toBeTrue()
        ->and($expense->approved_by)->toBe($this->hostAdmin->id);

    // Reversible: un-mark → the expense goes back to unapproved (correction).
    $this->actingAs($this->hostAdmin)->post("/deployments/{$d->id}/settlement", ['paid' => false])->assertRedirect();
    $expense->refresh();
    expect($expense->approved)->toBeFalse()
        ->and($expense->approved_by)->toBeNull();
});

it('CRITICAL: marking paid does not change the project P&L (labour still counted once)', function (): void {
    $d = settleDeployment(3, 100.0);
    app(DeploymentChargeService::class)->generateCharge($d->fresh());

    $pnl = app(ProfitabilityService::class);
    $before = $pnl->forProject($this->project->fresh());

    $this->actingAs($this->hostAdmin)->post("/deployments/{$d->id}/settlement", ['paid' => true])->assertRedirect();

    $after = $pnl->forProject($this->project->fresh());

    // Cost / revenue / profit are byte-identical before and after settlement.
    expect($after['cost'])->toBe($before['cost'])
        ->and($after['revenue'])->toBe($before['revenue'])
        ->and($after['profit'])->toBe($before['profit'])
        // Labour (the deployed worker's attendance) is the single count of 300.
        ->and($before['cost'])->toBe(300.0);
});
