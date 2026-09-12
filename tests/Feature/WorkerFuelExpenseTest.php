<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkerExpense;
use App\Services\Workers\WorkerFuelExpenseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Carlos García']);
});

/** Item 5 — a worker submission mints its mirror Expense immediately (pending). */
function submitFuelExpense(Company $company, Employee $employee, ?Vehicle $vehicle = null, string $amount = '45.50', string $date = '2026-08-10'): WorkerExpense
{
    $we = WorkerExpense::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'category' => 'fuel', 'amount' => $amount, 'date' => $date, 'description' => 'Diesel lleno',
    ]);
    if ($vehicle !== null) {
        $we->vehicle_id = $vehicle->id;
        $we->save();
    }
    app(WorkerFuelExpenseService::class)->mirrorOnSubmission($we);

    return $we->fresh();
}

it('mints a company mirror expense the moment a fuel expense is submitted', function (): void {
    $vehicle = Vehicle::factory()->create([
        'company_id' => $this->company->id, 'plate_number' => '1234-ABC', 'brand' => 'Ford', 'model' => 'Transit',
    ]);
    $we = submitFuelExpense($this->company, $this->employee, $vehicle);

    expect($we->auto_expense_id)->not->toBeNull();
    $expense = Expense::query()->withoutGlobalScopes()->find($we->auto_expense_id);
    expect((float) $expense->total)->toBe(45.50)
        ->and((float) $expense->subtotal)->toBe(45.50)
        ->and($expense->company_id)->toBe($this->company->id)
        ->and($expense->source)->toBe('worker_fuel')
        ->and($expense->source_id)->toBe($we->id)
        ->and($expense->date->toDateString())->toBe('2026-08-10')
        ->and($expense->category?->name)->toBe('Combustible')
        ->and($expense->notes)->toContain('Combustible')
        ->and($expense->notes)->toContain('1234-ABC')
        ->and($expense->notes)->toContain('Carlos García')
        // Starts UNAPPROVED — needs final approval in the Expenses tab.
        ->and($expense->approved)->toBeFalse();
});

it('does not create a duplicate mirror when approved twice', function (): void {
    submitFuelExpense($this->company, $this->employee);
    $mirror = Expense::query()->withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();

    $this->actingAs($this->admin)->post("/expenses/{$mirror->id}/approve", ['approved' => true]);
    $this->actingAs($this->admin)->post("/expenses/{$mirror->id}/approve", ['approved' => true]);

    expect(Expense::query()->withoutGlobalScopes()->where('source', 'worker_fuel')->count())->toBe(1);
});

it('creates a reimbursable mirror expense for a non-fuel worker expense too', function (): void {
    $we = WorkerExpense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'category' => 'materials', 'amount' => '30.00', 'date' => '2026-08-10',
    ]);
    app(WorkerFuelExpenseService::class)->mirrorOnSubmission($we);

    $mirror = Expense::query()->withoutGlobalScopes()->find($we->fresh()->auto_expense_id);
    expect($mirror)->not->toBeNull()
        ->and($mirror->source)->toBe('worker_expense')
        ->and($mirror->employee_id)->toBe($this->employee->id)
        ->and((bool) $mirror->is_reimbursable)->toBeTrue()
        // Starts UNAPPROVED — needs the admin's final approval to reach payroll.
        ->and($mirror->approved)->toBeFalse();
});

it('keeps the mirror when the worker expense is rejected in the Expenses tab', function (): void {
    $we = submitFuelExpense($this->company, $this->employee);
    $mirror = Expense::query()->withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();

    $this->actingAs($this->admin)->post("/expenses/{$mirror->id}/approve", ['approved' => false]);

    expect(Expense::query()->withoutGlobalScopes()->find($mirror->id))->not->toBeNull()
        ->and($we->fresh()->status->value)->toBe('rejected');
});

it('degrades the description gracefully when no vehicle is linked', function (): void {
    $we = submitFuelExpense($this->company, $this->employee); // no vehicle
    $expense = Expense::query()->withoutGlobalScopes()->find($we->auto_expense_id);
    expect($expense->notes)->toContain('Combustible')->toContain('Carlos García');
});

it('lets the admin give final approval to the fuel mirror in the Expenses tab', function (): void {
    $we = submitFuelExpense($this->company, $this->employee);
    $mirror = Expense::query()->withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();

    $this->actingAs($this->admin)->post("/expenses/{$mirror->id}/approve", ['approved' => true])
        ->assertRedirect();

    expect($mirror->fresh()->approved)->toBeTrue()
        ->and($we->fresh()->status->value)->toBe('approved');
});

it('does not delete the shared worker receipt when the mirror expense is removed', function (): void {
    Storage::fake('local');
    $we = WorkerExpense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'category' => 'fuel', 'amount' => '30.00', 'date' => '2026-08-10',
    ]);
    $we->receipt_path = UploadedFile::fake()->image('recibo.jpg')->store('worker-expense-receipts', 'local');
    $we->save();
    app(WorkerFuelExpenseService::class)->mirrorOnSubmission($we);

    $mirror = Expense::query()->withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();
    expect($mirror->file_path)->toBe($we->receipt_path);

    $this->actingAs($this->admin)->delete("/expenses/{$mirror->id}")->assertRedirect();

    // The mirror row is gone but the worker's receipt file survives.
    expect(Expense::query()->withoutGlobalScopes()->find($mirror->id))->toBeNull();
    Storage::disk('local')->assertExists($we->receipt_path);
});

it('backfills mirrors for PENDING worker expenses only, leaving approved legacy rows alone', function (): void {
    // A pending submission with NO mirror (submitted pre-cutover).
    $pending = WorkerExpense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'category' => 'materials', 'amount' => '20', 'date' => '2026-08-10', 'status' => 'pending',
    ]);
    // An APPROVED legacy row with no mirror — paid via PayrollService::pwaExpensesFor;
    // it must be LEFT ALONE (minting an unapproved mirror would unpay the worker).
    $legacy = WorkerExpense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'category' => 'materials', 'amount' => '30', 'date' => '2026-08-10', 'status' => 'approved',
    ]);

    $this->artisan('worker-expenses:backfill-mirrors')->assertSuccessful();

    expect($pending->fresh()->auto_expense_id)->not->toBeNull()  // mirrored
        ->and($legacy->fresh()->auto_expense_id)->toBeNull();     // untouched
    // The minted mirror is UNAPPROVED (awaiting review in the Expenses tab).
    expect(Expense::query()->withoutGlobalScopes()->find($pending->fresh()->auto_expense_id)->approved)->toBeFalse();
});

it('mints the mirror in the worker expense own company (tenancy)', function (): void {
    $other = Company::factory()->create();
    $otherAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $other->id]);
    submitFuelExpense($this->company, $this->employee);
    $mirror = Expense::query()->withoutGlobalScopes()->where('source', 'worker_fuel')->firstOrFail();

    expect($mirror->company_id)->toBe($this->company->id);

    // An admin of another company cannot reach the mirror — route binding 404s.
    $this->actingAs($otherAdmin)->post("/expenses/{$mirror->id}/approve", ['approved' => true])->assertNotFound();
    expect($mirror->fresh()->approved)->toBeFalse();
});
