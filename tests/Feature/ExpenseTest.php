<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        'bearable_by' => 'company',
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

it('derives the reimbursable flag from an employee-borne bearer', function (): void {
    $this->actingAs($this->admin)->post('/expenses', expensePayload([
        'bearable_by' => 'employee',
    ]))->assertRedirect();

    expect(Expense::withoutGlobalScopes()->firstOrFail())
        ->bearable_by->value->toBe('employee')
        ->is_reimbursable->toBeTrue()
        ->deduct_from_salary->toBeFalse();
});

it('flags an employee cost for salary deduction and it comes off payroll', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'wage_type' => 'monthly', 'base_salary' => '1000',
    ]);

    $this->actingAs($this->admin)->post('/expenses', expensePayload([
        'employee_id' => $employee->id,
        'bearable_by' => 'employee',
        'deduct_from_salary' => true,
        'date' => '2026-06-05',
        'subtotal' => 120,
    ]))->assertRedirect();

    $expense = Expense::withoutGlobalScopes()->firstOrFail();
    // A deduction is never reimbursed at the same time.
    expect($expense->is_reimbursable)->toBeFalse()->and($expense->deduct_from_salary)->toBeTrue();

    $this->actingAs($this->admin)->post("/expenses/{$expense->id}/approve", ['approved' => true]);
    app(PayrollService::class)->calculateMonth($this->company->id, '2026-06');

    $payroll = Payroll::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();
    expect((float) $payroll->getAttribute('expense_deductions'))->toBe(120.0)
        ->and((float) $payroll->getAttribute('net_amount'))->toBe(880.0); // 1000 base − 120
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

it('exports the filtered expenses to Excel and PDF', function (): void {
    $this->actingAs($this->admin)->post('/expenses', expensePayload());

    $this->actingAs($this->admin)->get('/expenses/export')->assertOk();

    $this->actingAs($this->admin)->get('/expenses/export-pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(AuditLog::where('action', 'exported')->where('module', 'expenses')->count())->toBeGreaterThanOrEqual(1);
});

it('uploads a receipt and serves it to a viewer, audited', function (): void {
    Storage::fake('local');

    $this->actingAs($this->admin)->post('/expenses', expensePayload([
        'file' => UploadedFile::fake()->create('recibo.pdf', 40, 'application/pdf'),
    ]))->assertRedirect();

    $expense = Expense::withoutGlobalScopes()->firstOrFail();
    expect($expense->file_path)->not->toBeNull();
    Storage::disk('local')->assertExists($expense->file_path);

    $this->actingAs($this->admin)->get("/expenses/{$expense->id}/receipt")->assertOk();

    expect(AuditLog::where('action', 'viewed')->where('module', 'expenses')->exists())->toBeTrue();
});

it('404s a receipt download when the expense has no file', function (): void {
    $this->actingAs($this->admin)->post('/expenses', expensePayload());
    $expense = Expense::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($this->admin)->get("/expenses/{$expense->id}/receipt")->assertNotFound();
});

it('creates, deactivates and deletes a custom expense category', function (): void {
    $this->actingAs($this->admin)->post('/expense-categories', ['name' => 'Materiales'])->assertRedirect();
    $category = ExpenseCategory::where('name', 'Materiales')->firstOrFail();
    expect($category->company_id)->toBe($this->company->id)->and($category->active)->toBeTrue();

    // An unused category is hard-deleted.
    $this->actingAs($this->admin)->delete("/expense-categories/{$category->id}")->assertRedirect();
    expect(ExpenseCategory::find($category->id))->toBeNull();

    // A referenced category is deactivated instead of deleted (keeps history).
    $used = ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Herramientas', 'active' => true]);
    Expense::factory()->create(['company_id' => $this->company->id, 'expense_category_id' => $used->id]);
    $this->actingAs($this->admin)->delete("/expense-categories/{$used->id}")->assertRedirect();
    expect($used->fresh()->active)->toBeFalse();
});

it('cannot manage another company expense category', function (): void {
    $other = Company::factory()->create();
    $foreign = ExpenseCategory::create(['company_id' => $other->id, 'name' => 'Otros', 'active' => true]);

    $this->actingAs($this->admin)->delete("/expense-categories/{$foreign->id}")->assertNotFound();
    expect($foreign->fresh()->active)->toBeTrue();
});

it('filters by type, category and payment status', function (): void {
    $category = ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Materiales', 'active' => true]);

    $this->actingAs($this->admin)->post('/expenses', expensePayload([
        'type' => 'factura', 'expense_category_id' => $category->id, 'payment_status' => 'paid',
    ]));
    $this->actingAs($this->admin)->post('/expenses', expensePayload([
        'type' => 'ticket', 'subtotal' => 30, 'payment_status' => 'unpaid',
    ]));

    $this->actingAs($this->admin)->get('/expenses?type=ticket')
        ->assertInertia(fn (Assert $p) => $p->has('expenses.data', 1));

    $this->actingAs($this->admin)->get("/expenses?expense_category_id={$category->id}")
        ->assertInertia(fn (Assert $p) => $p->has('expenses.data', 1));

    $this->actingAs($this->admin)->get('/expenses?payment_status=paid')
        ->assertInertia(fn (Assert $p) => $p->has('expenses.data', 1));
});
