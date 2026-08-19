<?php

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSplit;
use App\Models\User;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Smart Expense Split — one expense distributed across several categories.
 * Split amounts are VAT-inclusive and must sum to the expense total; a
 * single-category expense (no split rows) keeps working exactly as before.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->vendor = Vendor::factory()->create();
    $this->materials = ExpenseCategory::query()->create(['company_id' => $this->company->id, 'name' => 'Materials', 'active' => true]);
    $this->transport = ExpenseCategory::query()->create(['company_id' => $this->company->id, 'name' => 'Transport', 'active' => true]);
    $this->labor = ExpenseCategory::query()->create(['company_id' => $this->company->id, 'name' => 'Labor', 'active' => true]);
});

function splitPayload(array $splits, array $overrides = []): array
{
    return array_merge([
        'type' => 'factura',
        'vendor_id' => test()->vendor->id,
        'date' => '2026-08-10',
        'subtotal' => 100, // no VAT -> total 100
        'bearable_by' => 'company',
        'splits' => $splits,
    ], $overrides);
}

it('still saves a normal single-category expense (no splits)', function (): void {
    $this->actingAs($this->admin)->post('/expenses', [
        'type' => 'factura', 'vendor_id' => $this->vendor->id, 'date' => '2026-08-10',
        'subtotal' => 100, 'bearable_by' => 'company', 'expense_category_id' => $this->materials->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $expense = Expense::query()->latest('id')->firstOrFail();
    expect($expense->expense_category_id)->toBe($this->materials->id)
        ->and($expense->splits()->count())->toBe(0);
});

it('saves a multi-category split that sums to the total', function (): void {
    $this->actingAs($this->admin)->post('/expenses', splitPayload([
        ['expense_category_id' => $this->materials->id, 'amount' => 40, 'description' => 'cement'],
        ['expense_category_id' => $this->transport->id, 'amount' => 20, 'description' => null],
        ['expense_category_id' => $this->labor->id, 'amount' => 40, 'description' => null],
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $expense = Expense::query()->latest('id')->firstOrFail();
    expect($expense->expense_category_id)->toBeNull() // breakdown lives in the rows
        ->and($expense->splits()->count())->toBe(3)
        ->and((float) $expense->total)->toBe(100.0);

    $breakdown = $expense->load('splits.category')->categoryBreakdown();
    expect(collect($breakdown)->sum('amount'))->toBe(100.0);
});

it('rejects a split whose amounts do not sum to the total', function (): void {
    $this->actingAs($this->admin)->post('/expenses', splitPayload([
        ['expense_category_id' => $this->materials->id, 'amount' => 40],
        ['expense_category_id' => $this->transport->id, 'amount' => 30], // 70 != 100
    ]))->assertSessionHasErrors('splits');

    expect(Expense::query()->count())->toBe(0);
});

it('rejects a split with fewer than 2 categories', function (): void {
    $this->actingAs($this->admin)->post('/expenses', splitPayload([
        ['expense_category_id' => $this->materials->id, 'amount' => 100],
    ]))->assertSessionHasErrors('splits');

    expect(Expense::query()->count())->toBe(0);
});

it('cannot use another company category in a split', function (): void {
    $otherCompany = Company::factory()->create();
    $foreignCat = ExpenseCategory::query()->create(['company_id' => $otherCompany->id, 'name' => 'Foreign', 'active' => true]);

    $this->actingAs($this->admin)->post('/expenses', splitPayload([
        ['expense_category_id' => $this->materials->id, 'amount' => 60],
        ['expense_category_id' => $foreignCat->id, 'amount' => 40],
    ]))->assertSessionHasErrors('splits.1.expense_category_id');

    expect(Expense::query()->count())->toBe(0);
});

it('does not mass-assign the split amount', function (): void {
    // amount is server-set only — a mass-assign attempt is ignored.
    $split = new ExpenseSplit(['expense_category_id' => $this->materials->id, 'amount' => 999, 'description' => 'x']);

    expect($split->amount)->toBeNull();
});

it('categoryBreakdown falls back to the single category, then null', function (): void {
    $single = Expense::factory()->create(['company_id' => $this->company->id,
        'expense_category_id' => $this->materials->id, 'total' => 80,
    ]);
    $breakdown = $single->load('splits.category', 'category')->categoryBreakdown();
    expect($breakdown)->toHaveCount(1)
        ->and($breakdown[0]['category'])->toBe('Materials')
        ->and($breakdown[0]['amount'])->toBe(80.0);

    $none = Expense::factory()->create(['company_id' => $this->company->id, 'expense_category_id' => null]);
    expect($none->load('splits.category', 'category')->categoryBreakdown())->toBeNull();
});

it('reports expenses by category are split-aware', function (): void {
    // A split expense (Materials 60 / Transport 40) + a single-category one (Labor 100).
    $this->actingAs($this->admin)->post('/expenses', splitPayload([
        ['expense_category_id' => $this->materials->id, 'amount' => 60],
        ['expense_category_id' => $this->transport->id, 'amount' => 40],
    ], ['date' => '2026-08-05']));
    Expense::factory()->create(['company_id' => $this->company->id,
        'expense_category_id' => $this->labor->id, 'subtotal' => 100, 'total' => 100, 'date' => '2026-08-06',
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?module=financial&from=2026-08-01&to=2026-08-30')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('report.expense_by_category.Materials', fn ($v): bool => (float) $v === 60.0)
            ->where('report.expense_by_category.Transport', fn ($v): bool => (float) $v === 40.0)
            ->where('report.expense_by_category.Labor', fn ($v): bool => (float) $v === 100.0));
});
