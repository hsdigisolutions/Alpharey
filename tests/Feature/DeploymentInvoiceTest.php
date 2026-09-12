<?php

use App\Enums\AttendanceStatus;
use App\Enums\BillingMethod;
use App\Enums\DeploymentRateType;
use App\Enums\DeploymentStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\DeploymentCharge;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Services\Deployments\DeploymentChargeService;
use App\Services\Reports\ProfitabilityService;

/**
 * Inter-company deployment INVOICE flow (2026-09-12). At completion the HOME
 * company issues a real DEP-numbered, non-taxable invoice to the HOST; the host
 * settles it by approving the internal_deployment Expense (or via the Deployments
 * "Mark as paid"), which cascades the invoice to Paid — reversibly, and without
 * ever reopening the P&L double-count (the type is excluded from project P&L).
 */
beforeEach(function (): void {
    $this->home = Company::factory()->create(['name' => 'Shizukani']);
    $this->host = Company::factory()->create(['name' => 'Alovar']);
    $this->hostAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->host->id]);
    $this->homeAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->home->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->home->id, 'full_name' => 'Zubair Secret', 'daily_wage' => '100']);
    $this->project = Project::factory()->create(['company_id' => $this->host->id, 'name' => 'Obra Faro']);
});

function makeCompletedDeployment(int $days = 3, float $rate = 100.0): EmployeeDeployment
{
    $start = now()->startOfMonth();
    $d = EmployeeDeployment::query()->create([
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
    $d->update(['status' => DeploymentStatus::Completed]);
    app(DeploymentChargeService::class)->generateCharge($d->fresh());

    return $d;
}

function homeInvoiceFor(EmployeeDeployment $d): ?Invoice
{
    $charge = DeploymentCharge::query()->where('employee_deployment_id', $d->id)->first();

    return Invoice::query()->withoutGlobalScopes()->where('deployment_charge_id', $charge?->id)->first();
}

function hostExpenseFor(EmployeeDeployment $d): Expense
{
    $charge = DeploymentCharge::query()->where('employee_deployment_id', $d->id)->first();

    return Expense::query()->withoutGlobalScope(CompanyScope::class)->findOrFail($charge->expense_id);
}

it('generates a home-side DEP invoice at completion — non-taxable, counterparty=host, unpaid, worker-free', function (): void {
    $d = makeCompletedDeployment(3, 100.0);
    $invoice = homeInvoiceFor($d);

    expect($invoice)->not->toBeNull()
        ->and($invoice->company_id)->toBe($this->home->id)               // HOME issues it
        ->and($invoice->counterparty_company_id)->toBe($this->host->id)  // billed to HOST
        ->and($invoice->client_id)->toBeNull()
        ->and($invoice->project_id)->toBeNull()                          // never a home project
        ->and($invoice->number)->toStartWith('DEP-'.$this->home->id.'-')
        ->and($invoice->is_taxable)->toBeFalse()
        ->and($invoice->vat_rate)->toBeNull()
        ->and((float) $invoice->vat_amount)->toBe(0.0)
        ->and((float) $invoice->total)->toBe(300.0)
        ->and($invoice->payment_status)->toBe(PaymentStatus::Unpaid);

    // Worker identity must NOT appear on the invoice (the host sees this PDF).
    $lineText = $invoice->lineItems()->first()->description.' '.$invoice->notes;
    expect($lineText)->not->toContain('Zubair Secret');
});

it('cascades the host expense approval to Paid on the home invoice (all three records synced)', function (): void {
    $d = makeCompletedDeployment(3, 100.0);
    $expense = hostExpenseFor($d);

    $this->actingAs($this->hostAdmin)->post("/expenses/{$expense->id}/approve", ['approved' => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    $expense->refresh();
    $invoice = homeInvoiceFor($d);
    $charge = DeploymentCharge::query()->where('employee_deployment_id', $d->id)->first();

    expect($expense->approved)->toBeTrue()
        ->and($invoice->payment_status)->toBe(PaymentStatus::Paid)
        ->and((float) $invoice->paid_amount)->toBe(300.0)
        ->and($invoice->payment_date)->not->toBeNull()
        ->and($charge->settlement_status)->toBe('paid');
});

it('reverses cleanly when the host un-approves the expense (invoice back to Unpaid)', function (): void {
    $d = makeCompletedDeployment(3, 100.0);
    $expense = hostExpenseFor($d);

    $this->actingAs($this->hostAdmin)->post("/expenses/{$expense->id}/approve", ['approved' => true])->assertRedirect();
    $this->actingAs($this->hostAdmin)->post("/expenses/{$expense->id}/approve", ['approved' => false])->assertRedirect();

    $invoice = homeInvoiceFor($d);
    $charge = DeploymentCharge::query()->where('employee_deployment_id', $d->id)->first();

    expect(hostExpenseFor($d)->approved)->toBeFalse()
        ->and($invoice->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and((float) $invoice->paid_amount)->toBe(0.0)
        ->and($invoice->payments()->count())->toBe(0)   // settlement payment removed
        ->and($charge->settlement_status)->toBe('unpaid');
});

it('reaches the SAME paid state from the Deployments Mark-as-paid control', function (): void {
    $d = makeCompletedDeployment(2, 100.0);

    $this->actingAs($this->hostAdmin)->post("/deployments/{$d->id}/settlement", ['paid' => true])->assertRedirect();

    expect(homeInvoiceFor($d)->payment_status)->toBe(PaymentStatus::Paid)
        ->and(hostExpenseFor($d)->approved)->toBeTrue();
});

it('DOUBLE-COUNT GUARD: approving the settlement leaves the host project P&L byte-identical', function (): void {
    $d = makeCompletedDeployment(3, 100.0);
    $expense = hostExpenseFor($d);
    $pnl = app(ProfitabilityService::class);
    $before = $pnl->forProject($this->project->fresh());

    $this->actingAs($this->hostAdmin)->post("/expenses/{$expense->id}/approve", ['approved' => true])->assertRedirect();

    $after = $pnl->forProject($this->project->fresh());
    expect($after['cost'])->toBe($before['cost'])
        ->and($after['profit'])->toBe($before['profit'])
        ->and($before['cost'])->toBe(300.0);          // labour counted once, expense excluded by type
});

it('serves the invoice PDF to the HOST via the deployment, but not via the Invoices module (tenancy)', function (): void {
    $d = makeCompletedDeployment(2, 100.0);
    $invoice = homeInvoiceFor($d);

    // Host can read the PDF THROUGH the deployment (cross-company by design).
    $this->actingAs($this->hostAdmin)->get("/deployments/{$d->id}/invoice-pdf")->assertOk();

    // …but the home invoice is NOT reachable in the host's own Invoices module.
    $this->actingAs($this->hostAdmin)->get("/invoices/{$invoice->id}/pdf")->assertNotFound();
});

it('keeps the host expense out of the HOME company via tenancy (home cannot approve it)', function (): void {
    $d = makeCompletedDeployment(2, 100.0);
    $expense = hostExpenseFor($d);

    // The internal_deployment expense lives on the HOST — a home admin 404s.
    $this->actingAs($this->homeAdmin)->post("/expenses/{$expense->id}/approve", ['approved' => true])
        ->assertNotFound();
});

it('backfills an invoice for a completed charge that has none (idempotent)', function (): void {
    $d = makeCompletedDeployment(3, 100.0);

    // Simulate a pre-feature charge: drop its invoice so the backfill regenerates it.
    $invoice = homeInvoiceFor($d);
    $invoice->lineItems()->delete();
    $invoice->forceDelete();
    expect(homeInvoiceFor($d))->toBeNull();

    $this->artisan('deployments:backfill-invoices')->assertSuccessful();

    $regenerated = homeInvoiceFor($d);
    expect($regenerated)->not->toBeNull()
        ->and((float) $regenerated->total)->toBe(300.0)
        ->and($regenerated->number)->toStartWith('DEP-'.$this->home->id.'-');

    // Re-running does not duplicate.
    $this->artisan('deployments:backfill-invoices')->assertSuccessful();
    expect(Invoice::query()->withoutGlobalScopes()->where('deployment_charge_id', $regenerated->deployment_charge_id)->count())->toBe(1);
});
