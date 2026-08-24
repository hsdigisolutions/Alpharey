<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFine;
use App\Models\VehicleFuelRecord;
use App\Models\VehicleMaintenanceHistory;
use App\Models\VehicleSession;
use App\Models\Vendor;
use App\Services\Payroll\PayrollService;

/**
 * Part D — direct vehicle expense entry (fine / maintenance / fuel) from the
 * Expenses tab, through the two-gate flow; on final approval the matching
 * vehicle_* row appears and (if employee-borne) payroll deducts it.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Mateo Gil', 'wage_type' => 'daily', 'daily_wage' => '50']);
    $this->vehicle = Vehicle::factory()->for($this->company)->create();
});

it('creates a fine expense; final approval makes a linked VehicleFine charged to the driver', function (): void {
    $this->actingAs($this->admin)->post('/expenses/vehicle', [
        'vehicle_expense_type' => 'fine', 'vehicle_id' => $this->vehicle->id,
        'date' => '2026-08-10', 'amount' => '120', 'employee_id' => $this->employee->id,
        'bearable_by' => 'employee', 'reference' => 'ABC-123', 'notes' => 'Exceso de velocidad',
    ])->assertRedirect();

    $expense = Expense::withoutGlobalScopes()->where('vehicle_expense_type', 'fine')->firstOrFail();
    expect($expense->approved)->toBeFalse()
        ->and($expense->vehicle_id)->toBe($this->vehicle->id)
        ->and($expense->notes)->toContain('ABC-123');

    expect(VehicleFine::withoutGlobalScopes()->where('expense_id', $expense->id)->exists())->toBeFalse();

    $this->actingAs($this->admin)->post("/expenses/{$expense->id}/approve", ['approved' => true]);

    $fine = VehicleFine::withoutGlobalScopes()->where('expense_id', $expense->id)->first();
    expect($fine)->not->toBeNull()
        ->and($fine->charged_to)->toBe('employee')
        ->and((float) $fine->amount)->toBe(120.0);
});

it('creates a maintenance expense; final approval makes a VehicleMaintenanceHistory + payroll deduction when employee-borne', function (): void {
    $vendor = Vendor::factory()->create(['name' => 'Taller Central']);

    $this->actingAs($this->admin)->post('/expenses/vehicle', [
        'vehicle_expense_type' => 'maintenance', 'vehicle_id' => $this->vehicle->id,
        'date' => '2026-08-10', 'amount' => '200', 'vendor_id' => $vendor->id,
        'employee_id' => $this->employee->id, 'bearable_by' => 'employee', 'notes' => 'Golpe por accidente',
    ])->assertRedirect();

    $expense = Expense::withoutGlobalScopes()->where('vehicle_expense_type', 'maintenance')->firstOrFail();
    expect($expense->deduct_from_salary)->toBeTrue();

    // Not deducted before final approval.
    $before = app(PayrollService::class)->calculateFor($this->employee, $this->company->id, '2026-08');
    expect((float) $before->getAttribute('expense_deductions'))->toBe(0.0);

    $this->actingAs($this->admin)->post("/expenses/{$expense->id}/approve", ['approved' => true]);

    $m = VehicleMaintenanceHistory::withoutGlobalScopes()->where('expense_id', $expense->id)->first();
    expect($m)->not->toBeNull()->and($m->vendor_name)->toBe('Taller Central');

    $after = app(PayrollService::class)->calculateFor($this->employee, $this->company->id, '2026-08', $before);
    expect((float) $after->getAttribute('expense_deductions'))->toBe(200.0);
});

it('creates a fuel expense; final approval makes a linked VehicleFuelRecord', function (): void {
    $this->actingAs($this->admin)->post('/expenses/vehicle', [
        'vehicle_expense_type' => 'fuel', 'vehicle_id' => $this->vehicle->id,
        'date' => '2026-08-10', 'amount' => '70', 'litres' => '50', 'bearable_by' => 'company',
    ])->assertRedirect();

    $expense = Expense::withoutGlobalScopes()->where('vehicle_expense_type', 'fuel')->firstOrFail();
    $this->actingAs($this->admin)->post("/expenses/{$expense->id}/approve", ['approved' => true]);

    expect(VehicleFuelRecord::withoutGlobalScopes()->where('expense_id', $expense->id)->exists())->toBeTrue();
});

it('looks up the driver who had the vehicle on a given date from sessions', function (): void {
    VehicleSession::factory()->create([
        'vehicle_id' => $this->vehicle->id, 'employee_id' => $this->employee->id, 'company_id' => $this->company->id,
        'taken_at' => '2026-08-09 08:00:00', 'returned_at' => '2026-08-11 18:00:00',
    ]);

    $this->actingAs($this->admin)
        ->getJson("/vehicles/{$this->vehicle->id}/drivers-on-date?date=2026-08-10")
        ->assertOk()
        ->assertJsonPath('drivers.0.id', $this->employee->id);
});

it('rejects a direct vehicle expense on another company vehicle (tenancy)', function (): void {
    $other = Company::factory()->create();
    $foreign = Vehicle::factory()->for($other)->create();

    $this->actingAs($this->admin)->post('/expenses/vehicle', [
        'vehicle_expense_type' => 'fuel', 'vehicle_id' => $foreign->id, 'date' => '2026-08-10', 'amount' => '50',
    ])->assertSessionHasErrors('vehicle_id');
});
