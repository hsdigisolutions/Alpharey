<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->superAdmin = User::factory()->superAdmin()->create();
    $this->company = Company::factory()->create(['name' => 'Empresa Uno']);
});

it('shows the companies screen to the super admin only', function (): void {
    $this->actingAs($this->superAdmin)->get('/companies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Companies/Index')->has('companies', 1));

    $companyAdmin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->actingAs($companyAdmin)->get('/companies')->assertForbidden();

    $user = User::factory()->forCompany($this->company)->create();
    $this->actingAs($user)->get('/companies')->assertForbidden();
});

it('shows the welcome screen to the super admin only', function (): void {
    $this->actingAs($this->superAdmin)->get('/welcome')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));

    $user = User::factory()->forCompany($this->company)->create();
    $this->actingAs($user)->get('/welcome')->assertForbidden();
});

it('creates a company and audits it', function (): void {
    $this->actingAs($this->superAdmin)->post('/companies', [
        'name' => 'Empresa Nueva',
        'province' => 'Sevilla',
        'status' => 'active',
    ])->assertRedirect();

    expect(Company::query()->where('name', 'Empresa Nueva')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'created')->where('module', 'companies')->exists())->toBeTrue();
});

it('rejects duplicate company names', function (): void {
    $this->actingAs($this->superAdmin)->post('/companies', [
        'name' => 'Empresa Uno',
        'status' => 'active',
    ])->assertSessionHasErrors('name');
});

it('updates company details inline', function (): void {
    $this->actingAs($this->superAdmin)->put("/companies/{$this->company->id}", [
        'name' => 'Empresa Uno',
        'cif' => 'B12345678',
        'province' => 'Madrid',
        'status' => 'active',
    ])->assertRedirect();

    expect($this->company->fresh()->cif)->toBe('B12345678');
});

it('blocks removal while the company has users', function (): void {
    User::factory()->forCompany($this->company)->create();

    $this->actingAs($this->superAdmin)
        ->delete("/companies/{$this->company->id}", ['confirm_name' => 'Empresa Uno'])
        ->assertSessionHasErrors('confirm_name');

    expect(Company::query()->find($this->company->id))->not->toBeNull();
});

it('requires the exact company name to confirm removal', function (): void {
    $this->actingAs($this->superAdmin)
        ->delete("/companies/{$this->company->id}", ['confirm_name' => 'Wrong Name'])
        ->assertSessionHasErrors('confirm_name');

    expect(Company::query()->find($this->company->id))->not->toBeNull();
});

it('soft deletes a company after confirmation and safety checks', function (): void {
    $this->actingAs($this->superAdmin)
        ->delete("/companies/{$this->company->id}", ['confirm_name' => 'Empresa Uno'])
        ->assertRedirect();

    expect(Company::query()->find($this->company->id))->toBeNull()
        ->and(Company::withTrashed()->find($this->company->id))->not->toBeNull();
});

it('lets the super admin select and clear a company context', function (): void {
    $this->actingAs($this->superAdmin)
        ->post("/welcome/{$this->company->id}/select")
        ->assertRedirect('/dashboard');

    expect(session('current_company_id'))->toBe($this->company->id);

    $this->post('/welcome/clear')->assertRedirect('/welcome');

    expect(session('current_company_id'))->toBeNull();
});

it('never lets non-super-admins select a company', function (): void {
    $user = User::factory()->forCompany($this->company)->create();
    $other = Company::factory()->create();

    $this->actingAs($user)->post("/welcome/{$other->id}/select")->assertForbidden();
});
