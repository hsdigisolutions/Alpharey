<?php

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyBranding;
use Illuminate\Support\Facades\Storage;

/**
 * Change 3 — every company-specific PDF export carries the ACTING company's own
 * logo (via CompanyBranding + the shared exports.partials.logo-header partial),
 * never the generic AlphaRey mark. Absent silently when no logo is uploaded.
 */
it('resolves the acting company logo as a data URI via currentLogo', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $admin = User::factory()->companyAdmin()->forCompany($company)->create();

    Storage::disk('local')->put("company-logos/{$company->id}/logo.png", 'FAKEPNGBYTES');
    $company->logo_path = "company-logos/{$company->id}/logo.png";
    $company->save();

    $this->actingAs($admin);

    expect(CompanyBranding::currentLogo())->toStartWith('data:')
        ->and(CompanyBranding::currentLogo())->toContain('base64,');
});

it('returns no logo when the company has none', function (): void {
    $company = Company::factory()->create(['logo_path' => null]);
    $admin = User::factory()->companyAdmin()->forCompany($company)->create();
    $this->actingAs($admin);

    expect(CompanyBranding::currentLogo())->toBeNull();
});

it('renders the logo header partial only when a logo is present', function (): void {
    $withLogo = view('exports.partials.logo-header', ['logo' => 'data:image/png;base64,AAAA'])->render();
    $withoutLogo = view('exports.partials.logo-header', ['logo' => null])->render();

    expect($withLogo)->toContain('<img')->toContain('data:image/png;base64,AAAA');
    expect(trim($withoutLogo))->toBe('');
});

it('renders an expenses PDF export without error when a company logo is set', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $admin = User::factory()->companyAdmin()->forCompany($company)->create();
    Storage::disk('local')->put("company-logos/{$company->id}/logo.png", 'FAKEPNGBYTES');
    $company->logo_path = "company-logos/{$company->id}/logo.png";
    $company->save();

    $this->actingAs($admin)
        ->get('/expenses/export-pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
