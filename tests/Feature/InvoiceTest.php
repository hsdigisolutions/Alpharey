<?php

use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->client = Client::factory()->create();
});

function invoicePayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'sale',
        'sub_type' => 'final',
        'client_id' => test()->client->id,
        'invoice_date' => '2026-07-01',
        'status' => 'draft',
        'lines' => [
            ['description' => 'Pintura fachada', 'quantity' => 10, 'unit_price' => 100],
        ],
    ], $overrides);
}

it('computes the subtotal server-side from the line items', function (): void {
    $this->actingAs($this->admin)->post('/invoices', invoicePayload([
        'lines' => [
            ['description' => 'A', 'quantity' => 2, 'unit_price' => 50],   // 100
            ['description' => 'B', 'quantity' => 3, 'unit_price' => 10],   // 30
        ],
    ]))->assertRedirect();

    $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

    expect((float) $invoice->subtotal)->toBe(130.0)
        ->and((float) $invoice->total)->toBe(130.0)
        ->and($invoice->lineItems)->toHaveCount(2);
});

it('ignores any totals the client tries to send', function (): void {
    $this->actingAs($this->admin)->post('/invoices', invoicePayload([
        'subtotal' => 999999, 'total' => 999999, 'vat_amount' => 999999,
    ]))->assertRedirect();

    $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

    // 10 x 100 = 1000, not the injected 999999
    expect((float) $invoice->subtotal)->toBe(1000.0)
        ->and((float) $invoice->total)->toBe(1000.0);
});

it('adds no VAT line when the rate is blank (never defaults to 21%)', function (): void {
    $this->actingAs($this->admin)->post('/invoices', invoicePayload())->assertRedirect();

    $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

    expect($invoice->vat_rate)->toBeNull()
        ->and((float) $invoice->vat_amount)->toBe(0.0)
        ->and((float) $invoice->total)->toBe(1000.0);
});

it('applies the chosen VAT rate from the VatRate dropdown', function (): void {
    $this->actingAs($this->admin)->post('/invoices', invoicePayload([
        'vat_rate' => 'general', // 21%
    ]))->assertRedirect();

    $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

    expect($invoice->vat_rate->percent())->toBe(21.0)
        ->and((float) $invoice->vat_amount)->toBe(210.0)
        ->and((float) $invoice->total)->toBe(1210.0);
});

it('rejects a VAT value outside the official dropdown', function (): void {
    $this->actingAs($this->admin)
        ->post('/invoices', invoicePayload(['vat_rate' => '17']))
        ->assertSessionHasErrors('vat_rate');
});

it('discounts before VAT and withholds retention from the base', function (): void {
    // 1000 − 10% discount = 900 base; +21% VAT = 189; −15% retention = 135
    // total = 900 + 189 − 135 = 954
    $this->actingAs($this->admin)->post('/invoices', invoicePayload([
        'vat_rate' => 'general',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'retention_percent' => 15,
    ]))->assertRedirect();

    $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

    expect((float) $invoice->subtotal)->toBe(1000.0)
        ->and((float) $invoice->discount_amount)->toBe(100.0)
        ->and((float) $invoice->vat_amount)->toBe(189.0)
        ->and((float) $invoice->retention_amount)->toBe(135.0)
        ->and((float) $invoice->total)->toBe(954.0);
});

it('applies a fixed discount and never discounts below zero', function (): void {
    $this->actingAs($this->admin)->post('/invoices', invoicePayload([
        'discount_type' => 'fixed',
        'discount_value' => 5000, // larger than the 1000 subtotal
    ]))->assertRedirect();

    $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

    expect((float) $invoice->discount_amount)->toBe(1000.0)
        ->and((float) $invoice->total)->toBe(0.0);
});

it('derives payment status from the payment records', function (): void {
    $this->actingAs($this->admin)->post('/invoices', invoicePayload())->assertRedirect();
    $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

    expect($invoice->payment_status)->toBe(PaymentStatus::Unpaid);

    // Partial
    $this->actingAs($this->admin)->post("/invoices/{$invoice->id}/payments", [
        'amount' => 400, 'payment_date' => '2026-07-05',
    ])->assertRedirect();

    expect($invoice->fresh()->payment_status)->toBe(PaymentStatus::Partial)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(400.0);

    // Settled
    $this->actingAs($this->admin)->post("/invoices/{$invoice->id}/payments", [
        'amount' => 600, 'payment_date' => '2026-07-06',
    ])->assertRedirect();

    expect($invoice->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(1000.0);
});

it('falls the payment status back when a payment is removed', function (): void {
    $this->actingAs($this->admin)->post('/invoices', invoicePayload())->assertRedirect();
    $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($this->admin)->post("/invoices/{$invoice->id}/payments", [
        'amount' => 1000, 'payment_date' => '2026-07-05',
    ]);
    expect($invoice->fresh()->payment_status)->toBe(PaymentStatus::Paid);

    $payment = Payment::withoutGlobalScopes()->firstOrFail();
    $this->actingAs($this->admin)->delete("/payments/{$payment->id}")->assertRedirect();

    // Must not stay "Paid" once the money is gone
    expect($invoice->fresh()->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(0.0);
});

it('requires a client for a sale and a vendor for an expense', function (): void {
    $this->actingAs($this->admin)
        ->post('/invoices', invoicePayload(['client_id' => null]))
        ->assertSessionHasErrors('client_id');

    $this->actingAs($this->admin)
        ->post('/invoices', invoicePayload(['type' => 'expense', 'client_id' => null]))
        ->assertSessionHasErrors('vendor_id');
});

it('generates the invoice number server-side per company', function (): void {
    $this->actingAs($this->admin)->post('/invoices', invoicePayload(['number' => 'HACKED']));
    $this->actingAs($this->admin)->post('/invoices', invoicePayload());

    $numbers = Invoice::withoutGlobalScopes()->orderBy('id')->pluck('number')->all();

    expect($numbers[0])->toBe('F'.$this->company->id.'-00001')
        ->and($numbers[1])->toBe('F'.$this->company->id.'-00002');
});

it('recomputes totals when the line items change', function (): void {
    $this->actingAs($this->admin)->post('/invoices', invoicePayload());
    $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($this->admin)->put("/invoices/{$invoice->id}", invoicePayload([
        'lines' => [['description' => 'Nuevo', 'quantity' => 1, 'unit_price' => 250]],
    ]))->assertRedirect();

    expect((float) $invoice->fresh()->total)->toBe(250.0)
        ->and($invoice->fresh()->lineItems)->toHaveCount(1);
});

it('separates the sales and expenses tabs', function (): void {
    $vendor = Vendor::factory()->create();
    $this->actingAs($this->admin)->post('/invoices', invoicePayload());
    $this->actingAs($this->admin)->post('/invoices', invoicePayload([
        'type' => 'expense', 'client_id' => null, 'vendor_id' => $vendor->id,
    ]));

    $this->actingAs($this->admin)->get('/invoices?tab=sale')
        ->assertInertia(fn (Assert $p) => $p->component('Invoices/Index')->has('invoices.data', 1));

    $this->actingAs($this->admin)->get('/invoices?tab=expense')
        ->assertInertia(fn (Assert $p) => $p->has('invoices.data', 1));
});

it('shows only the acting company invoices', function (): void {
    $this->actingAs($this->admin)->post('/invoices', invoicePayload());

    $other = Company::factory()->create();
    $foreign = Invoice::factory()->create(['company_id' => $other->id]);

    $this->actingAs($this->admin)->get('/invoices')
        ->assertInertia(fn (Assert $p) => $p->has('invoices.data', 1));

    $this->actingAs($this->admin)->get("/invoices/{$foreign->id}")->assertNotFound();
});

it('sends a super admin with no company selected to Welcome instead of failing', function (): void {
    // Found in the browser: without a company context company_id was null and
    // the insert blew up with a 500. An invoice is always issued BY a company.
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->post('/invoices', invoicePayload())
        ->assertRedirect(route('welcome'));

    expect(Invoice::withoutGlobalScopes()->count())->toBe(0);
});

it('shows the create button on both tabs even with no company selected', function (): void {
    // Support report: as a Super Admin browsing all companies the New Invoice
    // button was hidden on both Ventas and Gastos, reading as a broken page.
    // It is permission-gated now; the store still redirects to Welcome (above).
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)->get('/invoices?tab=sale')
        ->assertInertia(fn (Assert $p) => $p->where('can.create', true));

    $this->actingAs($superAdmin)->get('/invoices?tab=expense')
        ->assertInertia(fn (Assert $p) => $p->where('can.create', true));
});

it('denies invoices without view permission', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->get('/invoices')->assertForbidden();
});

it('downloads an invoice PDF', function (): void {
    $this->actingAs($this->admin)->post('/invoices', invoicePayload());
    $invoice = Invoice::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($this->admin)->get("/invoices/{$invoice->id}/pdf")
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('auto-calculates invoice lines from a project using only client-borne costs', function (): void {
    $project = Project::factory()->forCompany($this->company)->create();

    // A client-borne approved expense feeds the invoice…
    Expense::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id,
        'approved' => true, 'bearable_by' => 'client', 'subtotal' => '200', 'total' => '200', 'date' => '2026-06-11',
    ]);
    // …a company-borne one must NOT.
    Expense::factory()->create([
        'company_id' => $this->company->id, 'project_id' => $project->id,
        'approved' => true, 'bearable_by' => 'company', 'subtotal' => '999', 'total' => '999', 'date' => '2026-06-11',
    ]);

    $res = $this->actingAs($this->admin)
        ->getJson("/invoices/project-costs?project_id={$project->id}&method=subtotal&margin=10");

    $res->assertOk();
    // 200 client cost × 1.10 margin = 220; the 999 company cost is excluded.
    expect((float) $res->json('lines.0.unit_price'))->toBe(220.0);
});

it('refuses to delete an invoice that has payments', function (): void {
    // payments cascade on invoice delete — without this guard, removing a
    // paid invoice would erase the record of money actually received.
    $this->actingAs($this->admin)->post('/invoices', invoicePayload());
    $invoice = Invoice::query()->firstOrFail();

    $this->actingAs($this->admin)->post("/invoices/{$invoice->id}/payments", [
        'amount' => 100, 'payment_date' => '2026-07-10',
    ]);

    $this->actingAs($this->admin)->delete("/invoices/{$invoice->id}")
        ->assertSessionHasErrors('invoice');
    expect(Invoice::query()->whereKey($invoice->id)->exists())->toBeTrue();

    // Remove the payment (audited, status re-derived) and the delete goes through.
    $payment = $invoice->payments()->firstOrFail();
    $this->actingAs($this->admin)->delete("/payments/{$payment->id}");
    $this->actingAs($this->admin)->delete("/invoices/{$invoice->id}")->assertRedirect();

    expect(Invoice::query()->whereKey($invoice->id)->exists())->toBeFalse();
});
