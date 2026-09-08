<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "Sujeta a IVA / Taxable" vs "No sujeta o exenta / Non-taxable" on invoices and
 * expenses — a classification + list filter that NEVER changes VAT calculation.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->client = Client::factory()->create();
});

function taxInvoice(array $overrides = []): array
{
    return array_merge([
        'type' => 'sale', 'sub_type' => 'final', 'client_id' => test()->client->id,
        'invoice_date' => '2026-07-01', 'status' => 'draft',
        'lines' => [['description' => 'Obra', 'quantity' => 1, 'unit_price' => 100]],
    ], $overrides);
}

function taxExpense(Company $company, array $overrides = []): array
{
    return array_merge([
        'type' => 'factura', 'date' => '2026-07-01', 'subtotal' => 100,
        'bearable_by' => 'company', 'payment_status' => 'unpaid',
    ], $overrides);
}

/* ---------- Invoices ---------- */

it('defaults an invoice to taxable when the flag is not sent', function (): void {
    $this->actingAs($this->admin)->post('/invoices', taxInvoice())->assertRedirect();
    expect(Invoice::withoutGlobalScopes()->firstOrFail()->is_taxable)->toBeTrue();
});

it('saves a non-taxable invoice', function (): void {
    $this->actingAs($this->admin)->post('/invoices', taxInvoice(['is_taxable' => false]))->assertRedirect();
    expect(Invoice::withoutGlobalScopes()->firstOrFail()->is_taxable)->toBeFalse();
});

it('does not change VAT calculation based on the taxable flag', function (): void {
    // Same 21% rate, one taxable one not → the VAT amount must be identical.
    $this->actingAs($this->admin)->post('/invoices', taxInvoice(['vat_rate' => 'general', 'is_taxable' => true]));
    $this->actingAs($this->admin)->post('/invoices', taxInvoice(['vat_rate' => 'general', 'is_taxable' => false]));

    $invoices = Invoice::withoutGlobalScopes()->orderBy('id')->get();
    expect((float) $invoices[0]->vat_amount)->toBe(21.0)
        ->and((float) $invoices[1]->vat_amount)->toBe(21.0)   // unaffected by is_taxable
        ->and((float) $invoices[0]->total)->toBe((float) $invoices[1]->total);
});

it('filters the invoice list by taxable status', function (): void {
    $this->actingAs($this->admin)->post('/invoices', taxInvoice(['is_taxable' => true]));
    $this->actingAs($this->admin)->post('/invoices', taxInvoice(['is_taxable' => false]));

    $this->actingAs($this->admin)->get('/invoices?tab=sale&taxable=non_taxable')
        ->assertInertia(fn (Assert $p) => $p->has('invoices.data', 1)
            ->where('invoices.data.0.is_taxable', false));

    $this->actingAs($this->admin)->get('/invoices?tab=sale&taxable=taxable')
        ->assertInertia(fn (Assert $p) => $p->has('invoices.data', 1)
            ->where('invoices.data.0.is_taxable', true));
});

/* ---------- Expenses ---------- */

it('defaults an expense to taxable and saves a non-taxable one', function (): void {
    $this->actingAs($this->admin)->post('/expenses', taxExpense($this->company))->assertRedirect();
    expect(Expense::withoutGlobalScopes()->latest('id')->first()->is_taxable)->toBeTrue();

    $this->actingAs($this->admin)->post('/expenses', taxExpense($this->company, ['is_taxable' => false]))->assertRedirect();
    expect(Expense::withoutGlobalScopes()->latest('id')->first()->is_taxable)->toBeFalse();
});

it('does not change expense VAT calculation based on the taxable flag', function (): void {
    $this->actingAs($this->admin)->post('/expenses', taxExpense($this->company, ['vat_rate' => 'general', 'is_taxable' => true]));
    $this->actingAs($this->admin)->post('/expenses', taxExpense($this->company, ['vat_rate' => 'general', 'is_taxable' => false]));

    $expenses = Expense::withoutGlobalScopes()->orderBy('id')->get();
    expect((float) $expenses[0]->vat_amount)->toBe(21.0)
        ->and((float) $expenses[1]->vat_amount)->toBe(21.0)
        ->and((float) $expenses[0]->total)->toBe((float) $expenses[1]->total);
});

it('filters the expense list by taxable status', function (): void {
    $this->actingAs($this->admin)->post('/expenses', taxExpense($this->company, ['is_taxable' => true]));
    $this->actingAs($this->admin)->post('/expenses', taxExpense($this->company, ['is_taxable' => false]));

    $this->actingAs($this->admin)->get('/expenses?taxable=non_taxable')
        ->assertInertia(fn (Assert $p) => $p->has('expenses.data', 1)
            ->where('expenses.data.0.is_taxable', false));
});
