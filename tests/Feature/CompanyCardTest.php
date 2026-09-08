<?php

use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\CompanyCard;
use App\Models\Expense;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Company payment cards (Settings → Tarjetas de empresa) + their use as an
 * expense payment method. Only a label + last four are stored; a card used by
 * an expense is deactivated, never hard-deleted (it carries history).
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

it('ships the company cards to the Settings page with an expense count', function (): void {
    CompanyCard::factory()->forCompany($this->company)->create(['label' => 'Visa Empresa', 'last_four' => '4242']);

    $this->actingAs($this->admin)->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('companyCards', 1)
            ->where('companyCards.0.label', 'Visa Empresa')
            ->where('companyCards.0.last_four', '4242')
            ->where('companyCards.0.expense_count', 0));
});

it('creates a card scoped to the acting company', function (): void {
    $this->actingAs($this->admin)->post('/admin/settings/company-cards', [
        'label' => 'BBVA Débito', 'last_four' => '1234',
    ])->assertRedirect();

    $card = CompanyCard::query()->where('label', 'BBVA Débito')->firstOrFail();
    expect($card->company_id)->toBe($this->company->id)
        ->and($card->last_four)->toBe('1234')
        ->and($card->active)->toBeTrue();
});

it('rejects a non-4-digit last_four', function (): void {
    $this->actingAs($this->admin)->post('/admin/settings/company-cards', [
        'label' => 'Bad', 'last_four' => '12',
    ])->assertSessionHasErrors('last_four');
});

it('rejects a duplicate card label within a company', function (): void {
    CompanyCard::factory()->forCompany($this->company)->create(['label' => 'Visa']);

    $this->actingAs($this->admin)->post('/admin/settings/company-cards', ['label' => 'Visa'])
        ->assertSessionHasErrors('label');
});

it('renames and deactivates a card', function (): void {
    $card = CompanyCard::factory()->forCompany($this->company)->create(['label' => 'Old', 'active' => true]);

    $this->actingAs($this->admin)->put("/admin/settings/company-cards/{$card->id}", [
        'label' => 'New', 'last_four' => '9999', 'active' => false,
    ])->assertRedirect();

    expect($card->fresh()->label)->toBe('New')
        ->and($card->fresh()->last_four)->toBe('9999')
        ->and($card->fresh()->active)->toBeFalse();
});

it('deletes an unused card', function (): void {
    $card = CompanyCard::factory()->forCompany($this->company)->create();

    $this->actingAs($this->admin)->delete("/admin/settings/company-cards/{$card->id}")
        ->assertRedirect();

    expect(CompanyCard::query()->whereKey($card->id)->exists())->toBeFalse();
});

it('refuses to delete a card used by an expense — deactivate instead', function (): void {
    $card = CompanyCard::factory()->forCompany($this->company)->create();
    Expense::factory()->create([
        'company_id' => $this->company->id,
        'company_card_id' => $card->id,
        'payment_method' => PaymentMethod::CompanyCard->value,
    ]);

    $this->actingAs($this->admin)->delete("/admin/settings/company-cards/{$card->id}")
        ->assertSessionHasErrors('card');

    // Still present (the admin should deactivate it instead).
    expect(CompanyCard::query()->whereKey($card->id)->exists())->toBeTrue();
});

it('cannot manage a card of another company (404)', function (): void {
    $foreign = CompanyCard::factory()->forCompany(Company::factory()->create())->create();

    $this->actingAs($this->admin)
        ->put("/admin/settings/company-cards/{$foreign->id}", ['label' => 'Hijack', 'active' => true])
        ->assertNotFound();
});

it('denies card management to a non-admin', function (): void {
    $manager = User::factory()->forCompany($this->company)->create(); // role: manager

    $this->actingAs($manager)->post('/admin/settings/company-cards', ['label' => 'X'])
        ->assertForbidden();
});

it('offers company_card as an expense payment method and saves the chosen card', function (): void {
    $card = CompanyCard::factory()->forCompany($this->company)->create(['label' => 'Visa Empresa']);

    // The expense form receives the active cards + the company_card method.
    $this->actingAs($this->admin)->get('/expenses')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('paymentMethods', fn ($m) => collect($m)->contains('company_card'))
            ->has('cards', 1)
            ->where('cards.0.label', 'Visa Empresa'));
});
