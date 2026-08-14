<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Vendor;
use App\Services\Documents\DocumentCenterService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    Storage::fake('local');
    $this->sa = User::factory()->superAdmin()->create();
    $this->company = Company::factory()->create();
});

/** @return Collection<int, array<string,mixed>> */
function centerRows(): Collection
{
    return collect(app(DocumentCenterService::class)->dataset()['rows']);
}

it('renders the command center with all five view payloads', function (): void {
    $this->actingAs($this->sa)->get('/documents')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Documents/Index')
        ->has('summary')
        ->has('urgent.expired')->has('urgent.missing')
        ->has('byEntity')->has('timeline')->has('dashboard')
        ->has('allPage.data'));
});

it('computes missing company documents that were never uploaded', function (): void {
    $this->actingAs($this->sa);

    $rows = centerRows();
    $missing = $rows->firstWhere(fn ($r) => $r['entity_type'] === 'company' && $r['type_key'] === 'poliza_rc');

    expect($missing)->not->toBeNull()
        ->and($missing['status'])->toBe('missing')
        ->and($missing['is_missing'])->toBeTrue();
});

it('applies the monthly-this-month rule to missing docs', function (): void {
    $this->actingAs($this->sa);

    // TGSS (monthly) uploaded THIS month → present, not missing.
    $this->post('/documents', ['entity_type' => 'company', 'entity_id' => $this->company->id, 'category' => 'company', 'type_key' => 'certificado_ss'])
        ->assertSessionHasNoErrors();

    $rows = centerRows();
    expect($rows->firstWhere(fn ($r) => $r['type_key'] === 'certificado_ss' && $r['is_missing']))->toBeNull();

    // Backdate it to last month → now missing again. Flush the 5-min dataset
    // cache: upload + backdate can share a second, colliding the signature.
    $doc = Document::query()->withoutGlobalScopes()->where('type_key', 'certificado_ss')->firstOrFail();
    $doc->forceFill(['created_at' => now()->subMonthNoOverflow()->startOfMonth()])->save();
    Cache::flush();

    $rows = centerRows();
    expect($rows->contains(fn ($r) => $r['type_key'] === 'certificado_ss' && $r['is_missing']))->toBeTrue()
        ->and($rows->contains(fn ($r) => $r['type_key'] === 'certificado_ss' && ! $r['is_missing']))->toBeFalse();
});

it('surfaces an expired vehicle as a read-only danger row', function (): void {
    Vehicle::factory()->for($this->company)->create([
        'active' => true,
        'insurance_expiry_date' => now()->subDays(10)->toDateString(),
    ]);

    $this->actingAs($this->sa);
    $row = centerRows()->firstWhere(fn ($r) => $r['entity_type'] === 'vehicle' && $r['type_key'] === 'insurance');

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('danger')
        ->and($row['is_vehicle'])->toBeTrue();
});

it('keeps a vendor certificado_ss visible — the monthly skip is company-only', function (): void {
    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $vendor = Vendor::factory()->create();

    $this->actingAs($admin)->post('/documents', [
        'entity_type' => 'vendor', 'entity_id' => $vendor->id, 'category' => 'vendor', 'type_key' => 'certificado_ss',
    ])->assertSessionHasNoErrors();

    $row = centerRows()->firstWhere(fn ($r) => $r['entity_type'] === 'vendor' && $r['type_key'] === 'certificado_ss');
    expect($row)->not->toBeNull()->and($row['is_missing'])->toBeFalse();
});

it('marks a vehicle with no expiry date as missing, not neutral', function (): void {
    Vehicle::factory()->for($this->company)->create([
        'active' => true,
        'insurance_expiry_date' => null,
        'ita_expiry_date' => now()->addYear()->toDateString(),
        'road_tax_expiry_date' => now()->addYear()->toDateString(),
    ]);

    $this->actingAs($this->sa);
    $row = centerRows()->firstWhere(fn ($r) => $r['entity_type'] === 'vehicle' && $r['type_key'] === 'insurance');
    expect($row['status'])->toBe('missing')->and($row['is_missing'])->toBeTrue();
});

it('scopes the center to a company admin\'s own company', function (): void {
    $companyB = Company::factory()->create();
    Employee::factory()->for($companyB)->create(['active' => true]);
    $adminA = User::factory()->companyAdmin()->forCompany($this->company)->create();

    $this->actingAs($adminA);
    $companyIds = centerRows()->pluck('company_id')->unique()->filter()->values();

    expect($companyIds->all())->toBe([$this->company->id]); // never company B
});

it('bulk-marks documents exempt (gated) and audits', function (): void {
    $this->actingAs($this->sa);
    $this->post('/documents', ['entity_type' => 'company', 'entity_id' => $this->company->id, 'category' => 'company', 'type_key' => 'rea'])
        ->assertSessionHasNoErrors();
    $doc = Document::query()->withoutGlobalScopes()->where('type_key', 'rea')->firstOrFail();

    $this->post('/documents/bulk-exempt', ['ids' => [$doc->id], 'exempt' => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($doc->refresh()->is_exempt)->toBeTrue();
});

it('refuses export without the documents.export ability', function (): void {
    $manager = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id, 'active' => true]);

    $this->actingAs($manager)->get('/documents/export?format=excel')->assertForbidden();
});

it('redirects the old compliance route to the command center', function (): void {
    $this->actingAs($this->sa)->get('/compliance')->assertRedirect('/documents');
});
