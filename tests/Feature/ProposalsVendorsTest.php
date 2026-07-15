<?php

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\User;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

/* ---------------- Vendors (shared pool) ---------------- */

it('shows vendors to every company (shared pool)', function (): void {
    Vendor::factory()->count(2)->create();

    $this->actingAs($this->admin)->get('/vendors')
        ->assertInertia(fn (Assert $page) => $page->component('Vendors/Index')->has('vendors.data', 2));
});

it('creates a vendor with contacts and payment terms', function (): void {
    $this->actingAs($this->admin)->post('/vendors', ['name' => 'Materiales SA', 'active' => true])->assertRedirect();
    $vendor = Vendor::query()->where('name', 'Materiales SA')->firstOrFail();

    $this->post("/vendors/{$vendor->id}/contacts", ['name' => 'Luis', 'is_primary' => true])->assertRedirect();
    $this->post("/vendors/{$vendor->id}/payment-terms", ['name' => '30 días', 'days' => 30, 'is_default' => true])->assertRedirect();

    expect($vendor->contacts()->count())->toBe(1)
        ->and($vendor->paymentTerms()->count())->toBe(1);
});

/* ---------------- Proposals (shared, optional VAT) ---------------- */

it('computes proposal totals server-side with optional VAT', function (): void {
    $client = Client::factory()->create();

    $this->actingAs($this->admin)->post('/proposals', [
        'client_id' => $client->id,
        'status' => 'draft',
        'vat_rate' => 'general', // 21%
        'line_items' => [
            ['description' => 'Mano de obra', 'qty' => 10, 'unit_price' => 50],   // 500
            ['description' => 'Material', 'qty' => 2, 'unit_price' => 250],        // 500
        ],
    ])->assertRedirect();

    $proposal = Proposal::query()->latest('id')->firstOrFail();

    expect((float) $proposal->subtotal)->toBe(1000.0)
        ->and((float) $proposal->vat_amount)->toBe(210.0)   // 21% of 1000
        ->and((float) $proposal->total_amount)->toBe(1210.0)
        ->and($proposal->number)->toStartWith('PROP-');
});

it('treats a blank VAT rate as no VAT line', function (): void {
    $client = Client::factory()->create();

    $this->actingAs($this->admin)->post('/proposals', [
        'client_id' => $client->id,
        'status' => 'draft',
        'vat_rate' => null,
        'line_items' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100]],
    ])->assertRedirect();

    $proposal = Proposal::query()->latest('id')->firstOrFail();

    expect((float) $proposal->subtotal)->toBe(100.0)
        ->and($proposal->vat_amount)->toBeNull()
        ->and((float) $proposal->total_amount)->toBe(100.0);
});

it('recomputes totals on update (never trusts client input)', function (): void {
    $proposal = Proposal::factory()->create(['subtotal' => 0, 'total_amount' => 0]);

    $this->actingAs($this->admin)->put("/proposals/{$proposal->id}", [
        'status' => 'sent',
        'vat_rate' => 'reducido', // 10%
        'line_items' => [['description' => 'Y', 'qty' => 4, 'unit_price' => 25]], // 100
    ])->assertRedirect();

    $fresh = $proposal->fresh();
    expect((float) $fresh->subtotal)->toBe(100.0)
        ->and((float) $fresh->vat_amount)->toBe(10.0)
        ->and((float) $fresh->total_amount)->toBe(110.0);
});

it('exports a proposal PDF (audited)', function (): void {
    $proposal = Proposal::factory()->create();

    $response = $this->actingAs($this->admin)->get("/proposals/{$proposal->id}/pdf");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and(AuditLog::query()->where('action', 'exported')->where('module', 'proposals')->exists())->toBeTrue();
});

it('denies proposals without view permission', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->get('/proposals')->assertForbidden();
});
