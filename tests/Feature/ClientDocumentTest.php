<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->adminA = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
    $this->client = Client::factory()->create();
});

it('uploads a client document tagged with the acting company and ships the smart payload', function (): void {
    $this->actingAs($this->adminA)->post('/documents', [
        'entity_type' => 'client', 'entity_id' => $this->client->id,
        'category' => 'client', 'type_key' => 'contrato',
        'expiry_date' => '2027-01-01',
        'contacts' => [['name' => 'Gestor Cliente', 'role' => 'Comercial']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc = Document::query()->withoutGlobalScopes()->where('type_key', 'contrato')->firstOrFail();

    expect($doc->company_id)->toBe($this->companyA->id)          // shared client, company-owned doc
        ->and($doc->documentable_type)->toBe(Client::class)
        ->and($doc->contacts[0]['name'])->toBe('Gestor Cliente')
        ->and($doc->expiry_date?->toDateString())->toBe('2027-01-01');

    $this->get("/clients/{$this->client->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Clients/Detail')
        ->where('documents.0.type_key', 'contrato')
        ->where('documents.0.contacts.0.role', 'Comercial')
        ->has('documents.0.field_defs')
        ->has('documents.0.history')
        ->has('documentFieldDefs.contrato'));
});

it('scopes a client document to the company that uploaded it', function (): void {
    $this->actingAs($this->adminA)->post('/documents', [
        'entity_type' => 'client', 'entity_id' => $this->client->id,
        'category' => 'client', 'type_key' => 'contrato',
    ])->assertSessionHasNoErrors();

    // Another company shares the client but must not see company A's paperwork.
    $companyB = Company::factory()->create();
    $adminB = User::factory()->companyAdmin()->forCompany($companyB)->create();

    $this->actingAs($adminB)->get("/clients/{$this->client->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('documents', 0));
});

it('rejects an unknown client document type', function (): void {
    $this->actingAs($this->adminA)->post('/documents', [
        'entity_type' => 'client', 'entity_id' => $this->client->id,
        'category' => 'client', 'type_key' => 'not_a_real_type',
    ])->assertStatus(422);
});

it('edits contacts on a client document without changing the version', function (): void {
    $this->actingAs($this->adminA)->post('/documents', [
        'entity_type' => 'client', 'entity_id' => $this->client->id,
        'category' => 'client', 'type_key' => 'seguro',
    ])->assertSessionHasNoErrors();
    $doc = Document::query()->withoutGlobalScopes()->where('type_key', 'seguro')->firstOrFail();

    $this->actingAs($this->adminA)->patch("/documents/{$doc->id}/metadata", [
        'contacts' => [['name' => 'Aseguradora', 'role' => 'Siniestros']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc->refresh();
    expect($doc->version)->toBe(1)->and($doc->contacts[0]['name'])->toBe('Aseguradora');
});
