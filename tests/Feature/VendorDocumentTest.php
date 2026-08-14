<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->adminA = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
    $this->vendor = Vendor::factory()->create();
});

it('uploads a vendor document tagged with the acting company and ships the smart payload', function (): void {
    $this->actingAs($this->adminA)->post('/documents', [
        'entity_type' => 'vendor', 'entity_id' => $this->vendor->id,
        'category' => 'vendor', 'type_key' => 'seguro_rc',
        'expiry_date' => '2027-03-01',
        'contacts' => [['name' => 'Correduría', 'role' => 'Pólizas']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc = Document::query()->withoutGlobalScopes()->where('type_key', 'seguro_rc')->firstOrFail();

    expect($doc->company_id)->toBe($this->companyA->id)
        ->and($doc->documentable_type)->toBe(Vendor::class)
        ->and($doc->contacts[0]['name'])->toBe('Correduría');

    $this->get("/vendors/{$this->vendor->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Vendors/Detail')
        ->where('documents.0.type_key', 'seguro_rc')
        ->where('documents.0.contacts.0.role', 'Pólizas')
        ->has('documents.0.field_defs')
        ->has('documentFieldDefs.seguro_rc'));
});

it('scopes a vendor document to the company that uploaded it', function (): void {
    $this->actingAs($this->adminA)->post('/documents', [
        'entity_type' => 'vendor', 'entity_id' => $this->vendor->id,
        'category' => 'vendor', 'type_key' => 'rea',
    ])->assertSessionHasNoErrors();

    $companyB = Company::factory()->create();
    $adminB = User::factory()->companyAdmin()->forCompany($companyB)->create();

    $this->actingAs($adminB)->get("/vendors/{$this->vendor->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('documents', 0));
});

it('rejects an unknown vendor document type', function (): void {
    $this->actingAs($this->adminA)->post('/documents', [
        'entity_type' => 'vendor', 'entity_id' => $this->vendor->id,
        'category' => 'vendor', 'type_key' => 'nope',
    ])->assertStatus(422);
});
