<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Models\UserModulePermission;
use Inertia\Testing\AssertableInertia as Assert;

function grantModule(User $user, Company $company, string $module, array $actions): void
{
    UserModulePermission::query()->create(array_merge([
        'user_id' => $user->id, 'company_id' => $company->id, 'module' => $module,
    ], $actions));
}

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->adminA = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
});

it('shows the same shared client pool to every company', function (): void {
    Client::factory()->count(3)->create();

    // Company A admin sees all clients...
    $this->actingAs($this->adminA)->get('/clients')
        ->assertInertia(fn (Assert $page) => $page->component('Clients/Index')->has('clients.data', 3));

    // ...and so does a Company B admin — clients are shared, not scoped
    $adminB = User::factory()->companyAdmin()->forCompany($this->companyB)->create();
    $this->actingAs($adminB)->get('/clients')
        ->assertInertia(fn (Assert $page) => $page->has('clients.data', 3));
});

it('denies the clients list without view permission', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($user)->get('/clients')->assertForbidden();
});

it('creates a client (no company field — shared)', function (): void {
    $this->actingAs($this->adminA)->post('/clients', [
        'name' => 'Ayuntamiento de Madrid',
        'client_type' => 'municipality',
        'active' => true,
    ])->assertRedirect();

    $client = Client::query()->where('name', 'Ayuntamiento de Madrid')->firstOrFail();
    expect($client->client_type->value)->toBe('municipality');
    // No company_id column exists — the shared model has none
    expect(Schema::hasColumn('clients', 'company_id'))->toBeFalse();
});

it('requires a name and a valid client type', function (): void {
    $this->actingAs($this->adminA)->post('/clients', ['client_type' => 'invalid'])
        ->assertSessionHasErrors(['name', 'client_type']);
});

it('lets an authorized custom user create clients', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();
    grantModule($user, $this->companyA, 'clients', ['can_view' => true, 'can_create' => true]);

    $this->actingAs($user)->post('/clients', ['name' => 'Nuevo', 'client_type' => 'company', 'active' => true])
        ->assertRedirect();

    expect(Client::query()->where('name', 'Nuevo')->exists())->toBeTrue();
});

it('soft deletes a client', function (): void {
    $client = Client::factory()->create();

    $this->actingAs($this->adminA)->delete("/clients/{$client->id}")->assertRedirect();

    expect(Client::query()->find($client->id))->toBeNull()
        ->and(Client::withTrashed()->find($client->id))->not->toBeNull();
});

it('adds a contact and a communication entry', function (): void {
    $client = Client::factory()->create();

    $this->actingAs($this->adminA)->post("/clients/{$client->id}/contacts", [
        'name' => 'Ana Ruiz', 'email' => 'ana@cliente.es',
    ])->assertRedirect();

    $this->post("/clients/{$client->id}/communications", [
        'type' => 'call', 'body' => 'Llamada de seguimiento',
    ])->assertRedirect();

    expect($client->contacts()->count())->toBe(1)
        ->and($client->communications()->count())->toBe(1);
});
