<?php

use App\Enums\InvoiceType;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Autocomplete\DescriptionAutocomplete;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->other = Company::factory()->create();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
});

function invoiceDescription(Company $company, string $description, ?string $createdAt = null, InvoiceType $type = InvoiceType::Sale): void
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'type' => $type]);
    $line = $invoice->lineItems()->create(['description' => $description, 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0]);
    if ($createdAt !== null) {
        DB::table('invoice_line_items')->where('id', $line->id)->update(['created_at' => $createdAt]);
    }
}

function expenseDescription(Company $company, string $description, ?string $createdAt = null): void
{
    $expense = Expense::factory()->create(['company_id' => $company->id]);
    $line = $expense->lineItems()->create(['description' => $description, 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0]);
    if ($createdAt !== null) {
        DB::table('expense_line_items')->where('id', $line->id)->update(['created_at' => $createdAt]);
    }
}

it('suggests matching descriptions from invoices and expenses combined', function (): void {
    invoiceDescription($this->company, 'Mano de obra — Reforma Madrid');
    expenseDescription($this->company, 'Mantenimiento equipos');
    invoiceDescription($this->company, 'Transporte y desplazamiento');

    $results = app(DescriptionAutocomplete::class)->descriptions('man', $this->company->id);

    expect($results)->toContain('Mano de obra — Reforma Madrid')
        ->toContain('Mantenimiento equipos')
        ->not->toContain('Transporte y desplazamiento');
});

it('includes both sales and purchase invoice line items', function (): void {
    invoiceDescription($this->company, 'Material venta', null, InvoiceType::Sale);
    invoiceDescription($this->company, 'Material compra', null, InvoiceType::Expense);

    $results = app(DescriptionAutocomplete::class)->descriptions('material', $this->company->id);

    expect($results)->toContain('Material venta')->toContain('Material compra');
});

it('requires at least two characters', function (): void {
    invoiceDescription($this->company, 'Mano de obra');

    expect(app(DescriptionAutocomplete::class)->descriptions('m', $this->company->id))->toBe([])
        ->and(app(DescriptionAutocomplete::class)->descriptions('', $this->company->id))->toBe([]);
});

it('caps results at eight', function (): void {
    foreach (range(1, 12) as $n) {
        invoiceDescription($this->company, "Prueba servicio {$n}");
    }

    expect(app(DescriptionAutocomplete::class)->descriptions('prueba', $this->company->id))->toHaveCount(8);
});

it('never leaks another company descriptions', function (): void {
    invoiceDescription($this->other, 'Secreto de la otra empresa');
    expenseDescription($this->other, 'Secreto gasto ajeno');

    $results = app(DescriptionAutocomplete::class)->descriptions('secreto', $this->company->id);

    expect($results)->toBe([]);
});

it('orders the most recently used first', function (): void {
    invoiceDescription($this->company, 'Mano antigua', '2026-01-01 09:00:00');
    invoiceDescription($this->company, 'Mano reciente', '2026-08-01 09:00:00');

    $results = app(DescriptionAutocomplete::class)->descriptions('mano', $this->company->id);

    expect($results[0])->toBe('Mano reciente');
});

it('returns descriptions as JSON from the endpoint for an authenticated admin', function (): void {
    invoiceDescription($this->company, 'Mano de obra — Instalación');

    $this->actingAs($this->admin)
        ->getJson('/autocomplete/descriptions?q=mano')
        ->assertOk()
        ->assertJson(['suggestions' => ['Mano de obra — Instalación']]);
});

it('denies the autocomplete endpoint to a guest', function (): void {
    $this->get('/autocomplete/descriptions?q=mano')->assertRedirect(route('login'));
});
