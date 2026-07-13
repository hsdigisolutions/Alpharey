<?php

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\ScopedItem;

beforeEach(function (): void {
    createScopedItemsTable();

    $this->companyA = Company::factory()->create(['name' => 'Empresa A']);
    $this->companyB = Company::factory()->create(['name' => 'Empresa B']);

    ScopedItem::forceCreate(['company_id' => $this->companyA->id, 'name' => 'item-a']);
    ScopedItem::forceCreate(['company_id' => $this->companyB->id, 'name' => 'item-b']);
});

it('shows users only rows of their own company', function (): void {
    $this->actingAs(User::factory()->forCompany($this->companyA)->create());

    expect(ScopedItem::query()->pluck('name')->all())->toBe(['item-a']);
});

it('returns null when fetching another company row by id', function (): void {
    $foreign = ScopedItem::acrossAllCompanies()->where('name', 'item-b')->firstOrFail();

    $this->actingAs(User::factory()->forCompany($this->companyA)->create());

    expect(ScopedItem::query()->find($foreign->id))->toBeNull();
});

it('scopes company admins exactly like regular users', function (): void {
    $this->actingAs(User::factory()->companyAdmin()->forCompany($this->companyB)->create());

    expect(ScopedItem::query()->pluck('name')->all())->toBe(['item-b']);
});

it('auto-fills company_id from the authenticated user on create', function (): void {
    $this->actingAs(User::factory()->forCompany($this->companyA)->create());

    $item = ScopedItem::query()->create(['name' => 'nuevo']);

    expect($item->company_id)->toBe($this->companyA->id);
});

it('does not allow mass assignment of company_id', function (): void {
    $this->actingAs(User::factory()->forCompany($this->companyA)->create());

    $item = ScopedItem::query()->create([
        'name' => 'sneaky',
        'company_id' => $this->companyB->id,
    ]);

    expect($item->refresh()->company_id)->toBe($this->companyA->id);
});

it('lets the super admin see all companies when browsing without a selection', function (): void {
    $this->actingAs(User::factory()->superAdmin()->create());

    expect(ScopedItem::query()->count())->toBe(2);
});

it('scopes the super admin to the explicitly selected company', function (): void {
    $this->actingAs(User::factory()->superAdmin()->create());

    app(CurrentCompany::class)->select($this->companyB);

    expect(ScopedItem::query()->pluck('name')->all())->toBe(['item-b']);

    app(CurrentCompany::class)->clearSelection();

    expect(ScopedItem::query()->count())->toBe(2);
});

it('rejects company switching for everyone except the super admin', function (): void {
    $this->actingAs(User::factory()->forCompany($this->companyA)->create());

    expect(fn () => app(CurrentCompany::class)->select($this->companyB))
        ->toThrow(HttpException::class);
});

it('shows guests nothing at all', function (): void {
    Auth::logout();

    expect(ScopedItem::query()->count())->toBe(0);
});

it('requires system code to opt out explicitly', function (): void {
    Auth::logout();

    expect(ScopedItem::query()->count())->toBe(0)
        ->and(ScopedItem::acrossAllCompanies()->count())->toBe(2);
});
