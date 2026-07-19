<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Payroll\PayrollService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->vendor = Vendor::factory()->create();
    $this->project = Project::factory()->forCompany($this->company)->create();
});

function expensePayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'factura',
        'vendor_id' => test()->vendor->id,
        'date' => '2026-06-10',
        'subtotal' => 100,
    ], $overrides);
}

it('derives VAT and total server-side', function (): void {
    $this->actingAs($this->admin)->post('/expenses', expensePayload([
        'subtotal' => 200, 'vat_rate' => 'general', // 21%
    ]))->assertRedirect();

    $expense = Expense::withoutGlobalScopes()->firstOrFail();

    expect((float) $expense->vat_amount)->toBe(42.0)
        ->and((float) $expense->total)->toBe(242.0);
});

it('adds no VAT when the rate is blank', function (): void {
    $this->actingAs($this->admin)->post('/expenses', expensePayload())->assertRedirect();

    $expense = Expense::withoutGlobalScopes()->firstOrFail();

    expect($expense->vat_rate)->toBeNull()
        ->and((float) $expense->vat_amount)->toBe(0.0)
        ->and((float) $expense->total)->toBe(100.0);
});

it('rejects a VAT value outside the official dropdown', function (): void {
    $this->actingAs($this->admin)
        ->post('/expenses', expensePayload(['vat_rate' => '17']))
        ->assertSessionHasErrors('vat_rate');
});

it('feeds a worker project expense into that month payroll', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'hourly', 'wage_rate' => '20',
    ]);

    // employee + project => worker project expense
    $this->actingAs($this->admin)->post('/expenses', expensePayload([
        'employee_id' => $employee->id,
        'project_id' => $this->project->id,
        'date' => '2026-06-12',
        'subtotal' => 75,
    ]))->assertRedirect();

    // Payroll pays back APPROVED claims only (the same rule as per-meter
    // measurements) — approve through the real endpoint first.
    $expense = Expense::withoutGlobalScopes()->firstOrFail();
    $this->actingAs($this->admin)->post("/expenses/{$expense->id}/approve", ['approved' => true]);

    app(PayrollService::class)->calculateMonth($this->company->id, '2026-06');

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    // no attendance, so the 75 is the whole gross
    expect((float) $payroll->getAttribute('project_expenses'))->toBe(75.0)
        ->and((float) $payroll->getAttribute('gross_pay'))->toBe(75.0);
});

it('keeps approval out of mass assignment', function (): void {
    $this->actingAs($this->admin)->post('/expenses', expensePayload([
        'approved' => true, // must be ignored
    ]))->assertRedirect();

    expect(Expense::withoutGlobalScopes()->firstOrFail()->approved)->toBeFalse();
});

it('approves and un-approves an expense', function (): void {
    $this->actingAs($this->admin)->post('/expenses', expensePayload());
    $expense = Expense::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($this->admin)->post("/expenses/{$expense->id}/approve", ['approved' => true])->assertRedirect();

    expect($expense->fresh()->approved)->toBeTrue()
        ->and($expense->fresh()->approved_by)->toBe($this->admin->id);

    $this->actingAs($this->admin)->post("/expenses/{$expense->id}/approve", ['approved' => false])->assertRedirect();

    expect($expense->fresh()->approved)->toBeFalse()
        ->and($expense->fresh()->approved_by)->toBeNull();
});

it('locks an approved expense against edits and deletes', function (): void {
    $this->actingAs($this->admin)->post('/expenses', expensePayload());
    $expense = Expense::withoutGlobalScopes()->firstOrFail();
    $this->actingAs($this->admin)->post("/expenses/{$expense->id}/approve", ['approved' => true]);

    $this->actingAs($this->admin)
        ->post("/expenses/{$expense->id}", expensePayload(['subtotal' => 9999]))
        ->assertStatus(422);

    $this->actingAs($this->admin)->delete("/expenses/{$expense->id}")->assertStatus(422);

    expect((float) $expense->fresh()->subtotal)->toBe(100.0);
});

it('filters by approval state', function (): void {
    $this->actingAs($this->admin)->post('/expenses', expensePayload());
    $this->actingAs($this->admin)->post('/expenses', expensePayload(['subtotal' => 50]));
    $first = Expense::withoutGlobalScopes()->first();
    $this->actingAs($this->admin)->post("/expenses/{$first->id}/approve", ['approved' => true]);

    // Larastan caught this comparing a Stringable to a string — always false.
    $this->actingAs($this->admin)->get('/expenses?approval=approved')
        ->assertInertia(fn (Assert $p) => $p->has('expenses.data', 1));

    $this->actingAs($this->admin)->get('/expenses?approval=pending')
        ->assertInertia(fn (Assert $p) => $p->has('expenses.data', 1));
});

it('shows only the acting company expenses', function (): void {
    $this->actingAs($this->admin)->post('/expenses', expensePayload());

    $other = Company::factory()->create();
    Expense::factory()->create(['company_id' => $other->id]);

    $this->actingAs($this->admin)->get('/expenses')
        ->assertInertia(fn (Assert $p) => $p->component('Expenses/Index')->has('expenses.data', 1));
});

it('denies expenses without view permission', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->get('/expenses')->assertForbidden();
});
