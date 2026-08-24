<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkerExpense;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Carlos García']);
});

function fuelExpense(Company $company, Employee $employee, ?Vehicle $vehicle = null, string $amount = '45.50', string $date = '2026-08-10'): WorkerExpense
{
    $we = WorkerExpense::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'category' => 'fuel',
        'amount' => $amount,
        'date' => $date,
        'description' => 'Diesel lleno',
    ]);
    if ($vehicle !== null) {
        $we->vehicle_id = $vehicle->id;
        $we->save();
    }

    return $we;
}

it('creates a company expense when a fuel expense is approved', function (): void {
    $vehicle = Vehicle::factory()->create([
        'company_id' => $this->company->id, 'plate_number' => '1234-ABC', 'brand' => 'Ford', 'model' => 'Transit',
    ]);
    $we = fuelExpense($this->company, $this->employee, $vehicle);

    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/approve")->assertRedirect();

    $we->refresh();
    expect($we->auto_expense_id)->not->toBeNull();

    $expense = Expense::query()->find($we->auto_expense_id);
    expect((float) $expense->total)->toBe(45.50)
        ->and((float) $expense->subtotal)->toBe(45.50)
        ->and($expense->company_id)->toBe($this->company->id)
        ->and($expense->source)->toBe('worker_fuel')
        ->and($expense->source_id)->toBe($we->id)
        ->and($expense->date->toDateString())->toBe('2026-08-10')
        ->and($expense->category?->name)->toBe('Combustible')
        ->and($expense->notes)->toContain('Combustible')
        ->and($expense->notes)->toContain('1234-ABC')
        ->and($expense->notes)->toContain('Carlos García');
});

it('does not create a duplicate when approved twice', function (): void {
    $we = fuelExpense($this->company, $this->employee);

    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/approve");
    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/approve");

    expect(Expense::query()->where('source', 'worker_fuel')->count())->toBe(1);
});

it('creates a reimbursable mirror expense for a non-fuel worker expense too', function (): void {
    $we = WorkerExpense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'category' => 'materials', 'amount' => '30.00', 'date' => '2026-08-10',
    ]);

    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/approve");

    $mirror = Expense::query()->find($we->fresh()->auto_expense_id);
    expect($mirror)->not->toBeNull()
        ->and($mirror->source)->toBe('worker_expense')
        ->and($mirror->employee_id)->toBe($this->employee->id)
        ->and((bool) $mirror->is_reimbursable)->toBeTrue()
        // Starts UNAPPROVED — needs the admin's final approval to reach payroll.
        ->and($mirror->approved)->toBeFalse();
});

it('keeps the auto expense when the worker expense is later rejected', function (): void {
    $we = fuelExpense($this->company, $this->employee);
    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/approve");
    $autoId = $we->fresh()->auto_expense_id;

    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/reject", ['reason' => 'Duplicado']);

    expect(Expense::query()->find($autoId))->not->toBeNull();
});

it('degrades the description gracefully when no vehicle is linked', function (): void {
    $we = fuelExpense($this->company, $this->employee); // no vehicle

    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/approve");

    $expense = Expense::query()->find($we->fresh()->auto_expense_id);
    expect($expense->notes)->toContain('Combustible')->toContain('Carlos García');
});

it('lets the admin give final approval to the fuel mirror in the Expenses tab', function (): void {
    $we = fuelExpense($this->company, $this->employee);
    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/approve");
    $mirror = Expense::query()->where('source', 'worker_fuel')->firstOrFail();

    // The old auto_fuel_locked guard is gone — the admin's final approval is now
    // exactly what releases the money into payroll.
    $this->actingAs($this->admin)->post("/expenses/{$mirror->id}/approve", ['approved' => true])
        ->assertRedirect();

    expect($mirror->fresh()->approved)->toBeTrue();
});

it('does not delete the shared worker receipt when the mirror expense is removed', function (): void {
    Storage::fake('local');
    $we = WorkerExpense::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'category' => 'fuel', 'amount' => '30.00', 'date' => '2026-08-10',
    ]);
    $we->receipt_path = UploadedFile::fake()->image('recibo.jpg')->store('worker-expense-receipts', 'local');
    $we->save();

    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/approve");
    $mirror = Expense::query()->where('source', 'worker_fuel')->firstOrFail();
    expect($mirror->file_path)->toBe($we->receipt_path);

    $this->actingAs($this->admin)->delete("/expenses/{$mirror->id}")->assertRedirect();

    // The mirror row is gone but the worker's receipt file survives.
    expect(Expense::query()->find($mirror->id))->toBeNull();
    Storage::disk('local')->assertExists($we->receipt_path);
});

it('creates the auto expense in the worker expense own company (tenancy)', function (): void {
    $other = Company::factory()->create();
    $otherAdmin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $other->id]);
    $we = fuelExpense($this->company, $this->employee);

    // An admin of another company cannot even reach it — route binding 404s.
    $this->actingAs($otherAdmin)->post("/worker-expenses/{$we->id}/approve")->assertNotFound();
    expect($we->fresh()->auto_expense_id)->toBeNull();

    // The owning company's admin approves → expense lands in the right company.
    $this->actingAs($this->admin)->post("/worker-expenses/{$we->id}/approve");
    expect(Expense::query()->find($we->fresh()->auto_expense_id)->company_id)->toBe($this->company->id);
});
