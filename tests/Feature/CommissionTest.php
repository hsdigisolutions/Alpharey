<?php

use App\Enums\CommissionStatus;
use App\Models\Client;
use App\Models\CommissionReportEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectEmployeeRate;
use App\Models\User;
use App\Services\Commissions\CommissionService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->forCompany($this->company)->create();
    $this->month = '2026-05';
});

/** An employee on the project earning `$percent` commission. */
function earner(float $percent = 5): Employee
{
    $employee = Employee::factory()->forCompany(test()->company)->create([
        'commission_percent' => (string) $percent,
    ]);

    // project_id + company_id are not mass-assignable here (the parent relation
    // normally supplies them) — set them directly.
    $link = new ProjectEmployeeRate(['employee_id' => $employee->id]);
    $link->project_id = test()->project->id;
    $link->company_id = test()->company->id;
    $link->save();

    return $employee;
}

/** A sale invoice on the project for `$total`. */
function saleInvoice(float $total = 1000, ?float $paid = null): Invoice
{
    $invoice = Invoice::factory()->create([
        'company_id' => test()->company->id,
        'client_id' => test()->client->id,
        'project_id' => test()->project->id,
        'invoice_date' => test()->month.'-10',
        'total' => (string) $total,
        'paid_amount' => (string) ($paid ?? 0),
    ]);

    return $invoice;
}

it('generates a commission per earner per invoice', function (): void {
    $employee = earner(5);
    saleInvoice(1000);

    $written = app(CommissionService::class)->generateMonth($this->company->id, $this->month);

    $entry = CommissionReportEntry::withoutGlobalScopes()->firstOrFail();

    // 1000 x 5% = 50
    expect($written)->toBe(1)
        ->and($entry->employee_id)->toBe($employee->id)
        ->and((float) $entry->base_amount)->toBe(1000.0)
        ->and((float) $entry->original_amount)->toBe(50.0)
        ->and($entry->status)->toBe(CommissionStatus::Draft);
});

it('skips employees who earn no commission', function (): void {
    earner(0); // commission_percent = 0
    saleInvoice(1000);

    $written = app(CommissionService::class)->generateMonth($this->company->id, $this->month);

    expect($written)->toBe(0)
        ->and(CommissionReportEntry::withoutGlobalScopes()->count())->toBe(0);
});

it('only pays earners actually assigned to the project', function (): void {
    earner(5);
    // an employee with a commission % but NOT on this project
    Employee::factory()->forCompany($this->company)->create(['commission_percent' => '10']);
    saleInvoice(1000);

    app(CommissionService::class)->generateMonth($this->company->id, $this->month);

    expect(CommissionReportEntry::withoutGlobalScopes()->count())->toBe(1);
});

it('ignores expense invoices and other months', function (): void {
    earner(5);
    Invoice::factory()->expense()->create([
        'company_id' => $this->company->id, 'project_id' => $this->project->id,
        'invoice_date' => $this->month.'-10', 'total' => '1000',
    ]);
    saleInvoice(500)->update(['invoice_date' => '2026-04-10']); // different month

    $written = app(CommissionService::class)->generateMonth($this->company->id, $this->month);

    expect($written)->toBe(0);
});

it('keeps the original amount when adjusted, with the reason', function (): void {
    earner(5);
    saleInvoice(1000);
    app(CommissionService::class)->generateMonth($this->company->id, $this->month);
    $entry = CommissionReportEntry::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($this->admin)->put("/commissions/{$entry->id}/adjust", [
        'adjusted_amount' => 35,
        'adjustment_reason' => 'Descuento pactado con el cliente',
    ])->assertRedirect();

    $entry->refresh();

    // the original is never overwritten — that pair is the audit story
    expect((float) $entry->original_amount)->toBe(50.0)
        ->and((float) $entry->adjusted_amount)->toBe(35.0)
        ->and($entry->adjustment_reason)->toBe('Descuento pactado con el cliente')
        ->and($entry->payableAmount())->toBe(35.0);
});

it('refuses an adjustment without a reason', function (): void {
    earner(5);
    saleInvoice(1000);
    app(CommissionService::class)->generateMonth($this->company->id, $this->month);
    $entry = CommissionReportEntry::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($this->admin)
        ->put("/commissions/{$entry->id}/adjust", ['adjusted_amount' => 35])
        ->assertSessionHasErrors('adjustment_reason');
});

it('locks an entry once finalized', function (): void {
    earner(5);
    saleInvoice(1000);
    app(CommissionService::class)->generateMonth($this->company->id, $this->month);
    $entry = CommissionReportEntry::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($this->admin)->post("/commissions/{$entry->id}/finalize")->assertRedirect();

    expect($entry->fresh()->status)->toBe(CommissionStatus::Finalized);

    // finalize is a one-way door: no further adjustment
    $this->actingAs($this->admin)->put("/commissions/{$entry->id}/adjust", [
        'adjusted_amount' => 1, 'adjustment_reason' => 'sneaky',
    ])->assertSessionHasErrors('status');

    expect((float) $entry->fresh()->original_amount)->toBe(50.0)
        ->and($entry->fresh()->adjusted_amount)->toBeNull();
});

it('never regenerates over a finalized entry', function (): void {
    earner(5);
    $invoice = saleInvoice(1000);
    $service = app(CommissionService::class);
    $service->generateMonth($this->company->id, $this->month);

    $entry = CommissionReportEntry::withoutGlobalScopes()->firstOrFail();
    $service->finalize($entry);

    // the invoice grows afterwards, then someone regenerates
    $invoice->update(['total' => '5000']);
    $service->generateMonth($this->company->id, $this->month);

    // the settled figure stands
    expect((float) $entry->fresh()->original_amount)->toBe(50.0);
});

it('requires finalizing before marking paid', function (): void {
    earner(5);
    saleInvoice(1000);
    app(CommissionService::class)->generateMonth($this->company->id, $this->month);
    $entry = CommissionReportEntry::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($this->admin)
        ->post("/commissions/{$entry->id}/paid")
        ->assertSessionHasErrors('status');

    app(CommissionService::class)->finalize($entry->refresh());

    $this->actingAs($this->admin)->post("/commissions/{$entry->id}/paid")->assertRedirect();

    expect($entry->fresh()->status)->toBe(CommissionStatus::Paid)
        ->and($entry->fresh()->paid_at)->not->toBeNull();
});

it('refreshes a draft entry when the invoice changes', function (): void {
    earner(5);
    $invoice = saleInvoice(1000);
    $service = app(CommissionService::class);
    $service->generateMonth($this->company->id, $this->month);

    $invoice->update(['total' => '2000']);
    $service->generateMonth($this->company->id, $this->month);

    // still one row, refreshed — not duplicated
    expect(CommissionReportEntry::withoutGlobalScopes()->count())->toBe(1)
        ->and((float) CommissionReportEntry::withoutGlobalScopes()->first()->original_amount)->toBe(100.0);
});

it('renders the commission screen scoped to the acting company', function (): void {
    earner(5);
    saleInvoice(1000);
    app(CommissionService::class)->generateMonth($this->company->id, $this->month);

    $this->actingAs($this->admin)->get('/commissions?month='.$this->month)
        ->assertOk()
        // 50.0 round-trips through JSON as 50 — assert on the decoded value
        ->assertInertia(fn (Assert $p) => $p->component('Commissions/Index')->has('entries', 1)->where('total', 50));
});

it('denies commissions without view permission', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->get('/commissions')->assertForbidden();
});

it('exports the month as a PDF', function (): void {
    earner(5);
    saleInvoice(1000);
    app(CommissionService::class)->generateMonth($this->company->id, $this->month);

    $this->actingAs($this->admin)->get('/commissions/pdf?month='.$this->month)
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});
