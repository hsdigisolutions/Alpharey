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

/**
 * PAYROLL_DEPLOYMENTS.md step 4 — when an Option A deployment completes, the
 * cross-charge must also POST an internal expense on the HOST company, so the
 * cost lands in the host's books and the project's cost, not just in a
 * deployment_charges row nobody reads.
 *
 * The two things that would hurt if wrong: charging the wrong company, and
 * double-charging on a re-run.
 */
beforeEach(function (): void {
    $this->home = Company::factory()->create(['name' => 'Empresa Origen']);
    $this->host = Company::factory()->create(['name' => 'Empresa Destino']);

    $this->employee = Employee::factory()->create(['company_id' => $this->home->id]);
    $this->project = Project::factory()->create(['company_id' => $this->host->id]);
});

/** An Option A deployment with `days` of host-project attendance behind it. */
function deploymentWithAttendance(int $days, float $rate = 100.0): EmployeeDeployment
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

    // Attendance is logged under the HOST company for a deployed worker. The
    // cross-charge is now the EXACT frozen cost (sum of total_amount), so each
    // day's frozen total is the daily rate → days × rate still holds.
    for ($i = 0; $i < $days; $i++) {
        Attendance::factory()->create([
            'company_id' => test()->host->id,
            'employee_id' => test()->employee->id,
            'project_id' => test()->project->id,
            'date' => $start->copy()->addDays($i)->toDateString(),
            'status' => AttendanceStatus::Present,
            'total_amount' => (string) $rate,
        ]);
    }

    return $deployment;
}

it('posts the internal expense on the HOST company, not the acting one', function (): void {
    // Act as the HOME company admin — the expense must still land on the host.
    $homeAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->home->id]);
    $this->actingAs($homeAdmin);

    $deployment = deploymentWithAttendance(3, 100.0);

    app(DeploymentChargeService::class)->generateCharge($deployment);

    $expense = Expense::query()->withoutGlobalScope(CompanyScope::class)
        ->where('type', ExpenseType::InternalDeployment->value)->first();

    expect($expense)->not->toBeNull()
        ->and($expense->company_id)->toBe($this->host->id)   // ← the host pays
        ->and($expense->company_id)->not->toBe($this->home->id)
        ->and((float) $expense->total)->toBe(300.0)          // 3 days × 100
        ->and($expense->project_id)->toBe($this->project->id);
});

it('carries no vendor and no VAT (no inter-company VAT invoice — decision 2)', function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]));

    app(DeploymentChargeService::class)->generateCharge(deploymentWithAttendance(2, 50.0));

    $expense = Expense::query()->withoutGlobalScope(CompanyScope::class)
        ->where('type', ExpenseType::InternalDeployment->value)->firstOrFail();

    // No vendor is what keeps it out of vendor expense reports.
    expect($expense->vendor_id)->toBeNull()
        ->and($expense->vat_rate)->toBeNull()
        ->and((float) $expense->vat_amount)->toBe(0.0)
        ->and((float) $expense->total)->toBe(100.0);
});

it('does NOT double-charge the host when the engine runs again', function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]));

    $deployment = deploymentWithAttendance(3, 100.0);
    $service = app(DeploymentChargeService::class);

    $service->generateCharge($deployment);
    $service->generateCharge($deployment->fresh());
    $service->generateCharge($deployment->fresh());

    $expenses = Expense::query()->withoutGlobalScope(CompanyScope::class)
        ->where('type', ExpenseType::InternalDeployment->value)->get();

    // One charge, one expense — refreshed in place, never duplicated.
    expect($expenses)->toHaveCount(1)
        ->and((float) $expenses->first()->total)->toBe(300.0)
        ->and(DeploymentCharge::query()->count())->toBe(1);
});

it('links the charge to the expense it posted', function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]));

    $charge = app(DeploymentChargeService::class)->generateCharge(deploymentWithAttendance(1, 80.0));

    $expense = Expense::query()->withoutGlobalScope(CompanyScope::class)
        ->where('type', ExpenseType::InternalDeployment->value)->firstOrFail();

    expect($charge->expense_id)->toBe($expense->id);
});

it('posts nothing for a non-Option-A arrangement', function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]));

    $deployment = deploymentWithAttendance(3, 100.0);
    // Force a non-A method past the request-layer guard to prove the engine
    // itself refuses (belt and braces — dev-skill Rule 13).
    $deployment->forceFill(['billing_method' => BillingMethod::OptionB->value])->save();

    $charge = app(DeploymentChargeService::class)->generateCharge($deployment->fresh());

    expect($charge)->toBeNull()
        ->and(Expense::query()->withoutGlobalScope(CompanyScope::class)
            ->where('type', ExpenseType::InternalDeployment->value)->count())->toBe(0);
});

it('refuses an internal_deployment type submitted through the expense form', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]);

    // A clerk must not be able to hand-create one — it would double-count in
    // the cross-company report.
    $this->actingAs($admin)->post('/expenses', [
        'type' => ExpenseType::InternalDeployment->value,
        'date' => now()->toDateString(),
        'subtotal' => '100',
        'total' => '100',
    ])->assertSessionHasErrors('type');
});

/*
 * Item A (2026-09) — the internal_deployment Gasto is read-only money safety.
 * The deployed worker's attendance is already counted ONCE as host project
 * labour (ProfitabilityService::attendanceAggregate), so APPROVING this expense
 * would double-count it. And a host admin must not edit or delete the engine's
 * own record. All three paths are refused server-side.
 */
it('refuses to APPROVE an internal_deployment expense (double-count guard)', function (): void {
    $hostAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]);
    app(DeploymentChargeService::class)->generateCharge(deploymentWithAttendance(2, 100.0));

    $expense = Expense::query()->withoutGlobalScope(CompanyScope::class)
        ->where('type', ExpenseType::InternalDeployment->value)->firstOrFail();

    $this->actingAs($hostAdmin)->post("/expenses/{$expense->id}/approve", ['approved' => true])
        ->assertSessionHasErrors('approved');

    expect($expense->fresh()->approved)->toBeFalse();
});

it('refuses to EDIT an internal_deployment expense', function (): void {
    $hostAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]);
    app(DeploymentChargeService::class)->generateCharge(deploymentWithAttendance(2, 100.0));

    $expense = Expense::query()->withoutGlobalScope(CompanyScope::class)
        ->where('type', ExpenseType::InternalDeployment->value)->firstOrFail();

    $this->actingAs($hostAdmin)->post("/expenses/{$expense->id}", [
        'type' => ExpenseType::Other->value, 'date' => now()->toDateString(),
        'subtotal' => '1', 'total' => '1',
    ])->assertStatus(422);

    // Untouched — still the engine's figure.
    expect((float) $expense->fresh()->total)->toBe(200.0);
});

it('refuses to DELETE an internal_deployment expense', function (): void {
    $hostAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]);
    app(DeploymentChargeService::class)->generateCharge(deploymentWithAttendance(2, 100.0));

    $expense = Expense::query()->withoutGlobalScope(CompanyScope::class)
        ->where('type', ExpenseType::InternalDeployment->value)->firstOrFail();

    $this->actingAs($hostAdmin)->delete("/expenses/{$expense->id}")->assertStatus(422);

    expect(Expense::query()->withoutGlobalScope(CompanyScope::class)->find($expense->id))->not->toBeNull();
});

/*
 * Item B (2026-09) — host-side detail for the cross-charge: WHY the expense is
 * here (project, days, period, live/locked) WITHOUT ever naming the worker.
 */
it('serves the host deployment detail with days/period/project and NO worker', function (): void {
    $hostAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]);
    app(DeploymentChargeService::class)->generateCharge(deploymentWithAttendance(3, 100.0));

    $expense = Expense::query()->withoutGlobalScope(CompanyScope::class)
        ->where('type', ExpenseType::InternalDeployment->value)->firstOrFail();

    $res = $this->actingAs($hostAdmin)->getJson("/expenses/{$expense->id}/deployment-detail");

    $res->assertOk()
        ->assertJson([
            'project' => $this->project->name,
            'home_company' => $this->home->name,
            'days' => 3,
            'amount' => 300.0,
            'status' => 'locked',
            'rate_type' => 'daily',
        ])
        // The deployed worker is NEVER named in the payload.
        ->assertJsonMissing(['employee' => $this->employee->full_name]);

    expect(array_keys($res->json()))->not->toContain('employee', 'employee_id', 'worker', 'full_name');
});

it('404s the deployment detail for a non-deployment expense', function (): void {
    $hostAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]);

    $ordinary = Expense::factory()->create([
        'company_id' => $this->host->id, 'type' => ExpenseType::Other->value,
    ]);

    $this->actingAs($hostAdmin)->getJson("/expenses/{$ordinary->id}/deployment-detail")->assertNotFound();
});

it('404s the deployment detail across companies (tenancy)', function (): void {
    app(DeploymentChargeService::class)->generateCharge(deploymentWithAttendance(2, 100.0));
    $expense = Expense::query()->withoutGlobalScope(CompanyScope::class)
        ->where('type', ExpenseType::InternalDeployment->value)->firstOrFail();

    // A stranger company's admin must not reach the host's expense.
    $stranger = Company::factory()->create();
    $strangerAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $stranger->id]);

    $this->actingAs($strangerAdmin)->getJson("/expenses/{$expense->id}/deployment-detail")->assertNotFound();
});
