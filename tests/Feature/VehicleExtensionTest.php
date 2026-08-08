<?php

use App\Enums\UserRole;
use App\Enums\VehicleOwnership;
use App\Enums\VehicleType;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDailyAssignment;
use App\Models\VehicleFine;
use App\Models\VehicleFuelRecord;
use App\Models\VehicleMaintenanceHistory;
use App\Notifications\DocumentAlertNotification;
use App\Services\Vehicles\VehicleCompliance;
use App\Services\Vehicles\VehicleService;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'company_id' => $this->company->id,
    ]);
    $this->actingAs($this->admin);
});

// ---------------------------------------------------------------------------
// Feature 1 — vehicle_type and road_tax_expiry_date fields
// ---------------------------------------------------------------------------

it('stores vehicle_type on create', function (): void {
    $this->post('/vehicles', [
        'plate_number' => 'VAN001',
        'ownership' => VehicleOwnership::Company->value,
        'vehicle_type' => VehicleType::Van->value,
    ])->assertSessionHasNoErrors();

    expect(Vehicle::query()->where('plate_number', 'VAN001')->first()->vehicle_type)
        ->toBe(VehicleType::Van);
});

it('stores road_tax_expiry_date on create', function (): void {
    $expiry = now()->addDays(180)->toDateString();

    $this->post('/vehicles', [
        'plate_number' => 'TAX001',
        'ownership' => VehicleOwnership::Company->value,
        'road_tax_expiry_date' => $expiry,
    ])->assertSessionHasNoErrors();

    expect(Vehicle::query()->where('plate_number', 'TAX001')->first()->road_tax_expiry_date->toDateString())
        ->toBe($expiry);
});

// ---------------------------------------------------------------------------
// Feature 6 — road_tax included in compliance grading
// ---------------------------------------------------------------------------

it('grades road_tax on the traffic light', function (): void {
    $vehicle = Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'insurance_expiry_date' => now()->addDays(200)->toDateString(),
        'ita_expiry_date' => now()->addDays(200)->toDateString(),
        'road_tax_expiry_date' => now()->addDays(45)->toDateString(),
    ]);

    $compliance = app(VehicleCompliance::class);

    expect($compliance->of($vehicle, 'road_tax')[0])->toBe('warn')
        ->and($compliance->worst($vehicle))->toBe('warn');
});

it('treats a missing road_tax as neutral, not ok', function (): void {
    $vehicle = Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'insurance_expiry_date' => now()->addDays(200)->toDateString(),
        'ita_expiry_date' => now()->addDays(200)->toDateString(),
        'road_tax_expiry_date' => null,
    ]);

    expect(app(VehicleCompliance::class)->worst($vehicle))->toBe('neutral');
});

it('alerts on road_tax milestone like any other document', function (): void {
    Notification::fake();

    Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'insurance_expiry_date' => now()->addDays(200)->toDateString(),
        'ita_expiry_date' => now()->addDays(200)->toDateString(),
        'road_tax_expiry_date' => now()->addDays(30)->toDateString(),
    ]);

    $this->artisan('verto:scan-documents')->assertSuccessful();

    Notification::assertSentTo($this->admin, DocumentAlertNotification::class);
});

// ---------------------------------------------------------------------------
// Feature 4 — vendor_name on maintenance records
// ---------------------------------------------------------------------------

it('stores vendor_name on a maintenance record', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);

    $this->post("/vehicles/{$vehicle->id}/maintenance", [
        'maintenance_type' => 'oil_change',
        'maintenance_date' => now()->toDateString(),
        'vendor_name' => 'Talleres Garcia',
        'cost' => '95',
    ])->assertSessionHasNoErrors();

    expect(VehicleMaintenanceHistory::query()->where('vehicle_id', $vehicle->id)->first()->vendor_name)
        ->toBe('Talleres Garcia');
});

// ---------------------------------------------------------------------------
// Feature 2 — daily assignment CRUD
// ---------------------------------------------------------------------------

it('logs a daily assignment', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);

    $this->post("/vehicles/{$vehicle->id}/daily-assignments", [
        'assigned_date' => '2026-07-28',
        'employee_id' => $employee->id,
        'notes' => 'Reparto zona norte',
    ])->assertSessionHasNoErrors();

    $record = VehicleDailyAssignment::query()->where('vehicle_id', $vehicle->id)->first();

    expect($record)->not->toBeNull()
        ->and($record->employee_id)->toBe($employee->id)
        ->and($record->assigned_date->toDateString())->toBe('2026-07-28')
        ->and($record->notes)->toBe('Reparto zona norte');
});

it('replaces an existing entry when the same date is logged twice', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $first = Employee::factory()->create(['company_id' => $this->company->id]);
    $second = Employee::factory()->create(['company_id' => $this->company->id]);

    $this->post("/vehicles/{$vehicle->id}/daily-assignments", [
        'assigned_date' => '2026-07-28',
        'employee_id' => $first->id,
    ]);

    $this->post("/vehicles/{$vehicle->id}/daily-assignments", [
        'assigned_date' => '2026-07-28',
        'employee_id' => $second->id,
    ]);

    expect(VehicleDailyAssignment::query()->where('vehicle_id', $vehicle->id)->count())->toBe(1)
        ->and(VehicleDailyAssignment::query()->where('vehicle_id', $vehicle->id)->first()->employee_id)
        ->toBe($second->id);
});

it('deletes a daily assignment', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $assignment = VehicleDailyAssignment::factory()->create([
        'vehicle_id' => $vehicle->id,
        'company_id' => $this->company->id,
    ]);

    $this->delete("/vehicles/{$vehicle->id}/daily-assignments/{$assignment->id}")
        ->assertSessionHasNoErrors();

    expect(VehicleDailyAssignment::query()->whereKey($assignment->id)->exists())->toBeFalse();
});

it('cannot delete a daily assignment through another vehicle', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $decoy = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $assignment = VehicleDailyAssignment::factory()->create([
        'vehicle_id' => $vehicle->id,
        'company_id' => $this->company->id,
    ]);

    $this->delete("/vehicles/{$decoy->id}/daily-assignments/{$assignment->id}")->assertNotFound();

    expect(VehicleDailyAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
});

it('daily assignment company_id follows the vehicle, not request input', function (): void {
    $other = Company::factory()->create();
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);

    $this->post("/vehicles/{$vehicle->id}/daily-assignments", [
        'assigned_date' => '2026-07-29',
        'company_id' => $other->id,
    ])->assertSessionHasNoErrors();

    expect(VehicleDailyAssignment::query()->withoutGlobalScopes()
        ->where('vehicle_id', $vehicle->id)->first()->company_id)
        ->toBe($this->company->id);
});

// ---------------------------------------------------------------------------
// Feature 3 — traffic fines + auto-employee detection + expense creation
// ---------------------------------------------------------------------------

it('logs a traffic fine charged to the company and creates an expense', function (): void {
    $vehicle = Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'plate_number' => '1234ABC',
    ]);

    $this->post("/vehicles/{$vehicle->id}/fines", [
        'fine_date' => '2026-07-15',
        'amount' => '200.00',
        'description' => 'Exceso de velocidad',
        'authority' => 'DGT',
        'charged_to' => 'company',
    ])->assertSessionHasNoErrors();

    $fine = VehicleFine::query()->where('vehicle_id', $vehicle->id)->first();

    expect($fine)->not->toBeNull()
        ->and((float) $fine->amount)->toBe(200.0)
        ->and($fine->charged_to)->toBe('company')
        ->and($fine->expense_id)->not->toBeNull();

    $expense = Expense::query()->withoutGlobalScopes()->find($fine->expense_id);
    expect($expense)->not->toBeNull()
        ->and($expense->company_id)->toBe($this->company->id)
        ->and((float) $expense->total)->toBe(200.0);
});

it('logs a fine charged to an employee without creating an expense', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);

    $this->post("/vehicles/{$vehicle->id}/fines", [
        'fine_date' => '2026-07-15',
        'amount' => '100.00',
        'description' => 'Aparcamiento indebido',
        'charged_to' => 'employee',
        'employee_id' => $employee->id,
    ])->assertSessionHasNoErrors();

    $fine = VehicleFine::query()->where('vehicle_id', $vehicle->id)->first();

    expect($fine->charged_to)->toBe('employee')
        ->and($fine->expense_id)->toBeNull()
        ->and($fine->employee_id)->toBe($employee->id);
});

it('rejects a fine charged to an employee from another company', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $foreign = Employee::factory()->create(['company_id' => Company::factory()->create()->id]);

    $this->post("/vehicles/{$vehicle->id}/fines", [
        'fine_date' => '2026-07-15', 'amount' => '100', 'description' => 'x',
        'charged_to' => 'employee', 'employee_id' => $foreign->id,
    ])->assertSessionHasErrors('employee_id');

    expect(VehicleFine::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('flags and unflags a fine for salary deduction (never automatic)', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $fine = VehicleFine::factory()->create([
        'company_id' => $this->company->id, 'vehicle_id' => $vehicle->id,
        'employee_id' => $employee->id, 'amount' => '75', 'charged_to' => 'employee',
    ]);

    // Flag it for a specific month.
    $this->put("/vehicles/{$vehicle->id}/fines/{$fine->id}/deduct-salary", [
        'deduct_from_salary' => true, 'deduction_month' => '2026-08',
    ])->assertSessionHasNoErrors();

    expect($fine->fresh()->deduct_from_salary)->toBeTrue()
        ->and($fine->fresh()->deduction_month)->toBe('2026-08');

    // Unflag it.
    $this->put("/vehicles/{$vehicle->id}/fines/{$fine->id}/deduct-salary", [
        'deduct_from_salary' => false,
    ])->assertSessionHasNoErrors();

    expect($fine->fresh()->deduct_from_salary)->toBeFalse()
        ->and($fine->fresh()->deduction_month)->toBeNull();
});

it('auto-detects the driver from the daily assignment log on the fine date', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $service = app(VehicleService::class);

    $service->logDailyAssignment($vehicle, '2026-07-15', ['employee_id' => $employee->id]);

    $this->post("/vehicles/{$vehicle->id}/fines", [
        'fine_date' => '2026-07-15',
        'amount' => '60.00',
        'description' => 'Semaforo en rojo',
        'charged_to' => 'company',
    ])->assertSessionHasNoErrors();

    $fine = VehicleFine::query()->where('vehicle_id', $vehicle->id)->first();

    expect($fine->employee_id)->toBe($employee->id);
});

it('deletes a fine and cleans up its expense', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);

    $this->post("/vehicles/{$vehicle->id}/fines", [
        'fine_date' => '2026-07-15',
        'amount' => '150.00',
        'description' => 'Test fine',
        'charged_to' => 'company',
    ]);

    $fine = VehicleFine::query()->where('vehicle_id', $vehicle->id)->first();
    $expenseId = $fine->expense_id;

    $this->delete("/vehicles/{$vehicle->id}/fines/{$fine->id}")
        ->assertSessionHasNoErrors();

    expect(VehicleFine::query()->whereKey($fine->id)->exists())->toBeFalse()
        ->and(Expense::query()->withoutGlobalScopes()->whereKey($expenseId)->exists())->toBeFalse();
});

it('cannot delete a fine through another vehicle', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $decoy = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $fine = VehicleFine::factory()->create([
        'vehicle_id' => $vehicle->id,
        'company_id' => $this->company->id,
    ]);

    $this->delete("/vehicles/{$decoy->id}/fines/{$fine->id}")->assertNotFound();

    expect(VehicleFine::query()->withoutGlobalScopes()->whereKey($fine->id)->exists())->toBeTrue();
});

it('fine expense company_id follows the vehicle, never the session', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $service = app(VehicleService::class);

    $fine = $service->logFine($vehicle, [
        'fine_date' => '2026-07-15',
        'amount' => '80.00',
        'description' => 'Test',
        'charged_to' => 'company',
    ]);

    $expense = Expense::query()->withoutGlobalScopes()->find($fine->expense_id);

    expect($expense->company_id)->toBe($vehicle->company_id);
});

// ---------------------------------------------------------------------------
// Feature 5 — fuel records
// ---------------------------------------------------------------------------

it('logs a fuel fill-up', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);

    $this->post("/vehicles/{$vehicle->id}/fuel", [
        'fuel_date' => '2026-07-20',
        'litres' => '45.5',
        'cost_per_litre' => '1.650',
        'total_cost' => '75.08',
        'mileage_at_fill' => 52000,
        'payment_method' => 'company_card',
    ])->assertSessionHasNoErrors();

    $record = VehicleFuelRecord::query()->where('vehicle_id', $vehicle->id)->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->litres)->toBe(45.5)
        ->and((float) $record->total_cost)->toBe(75.08)
        ->and($record->mileage_at_fill)->toBe(52000);
});

it('deletes a fuel record', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $record = VehicleFuelRecord::factory()->create([
        'vehicle_id' => $vehicle->id,
        'company_id' => $this->company->id,
    ]);

    $this->delete("/vehicles/{$vehicle->id}/fuel/{$record->id}")
        ->assertSessionHasNoErrors();

    expect(VehicleFuelRecord::query()->whereKey($record->id)->exists())->toBeFalse();
});

it('cannot delete a fuel record through another vehicle', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $decoy = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $record = VehicleFuelRecord::factory()->create([
        'vehicle_id' => $vehicle->id,
        'company_id' => $this->company->id,
    ]);

    $this->delete("/vehicles/{$decoy->id}/fuel/{$record->id}")->assertNotFound();

    expect(VehicleFuelRecord::query()->whereKey($record->id)->exists())->toBeTrue();
});

it('fuel record company_id follows the vehicle, not request input', function (): void {
    $other = Company::factory()->create();
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);

    $this->post("/vehicles/{$vehicle->id}/fuel", [
        'fuel_date' => '2026-07-20',
        'litres' => '40',
        'cost_per_litre' => '1.6',
        'total_cost' => '64',
        'company_id' => $other->id,
    ])->assertSessionHasNoErrors();

    expect(VehicleFuelRecord::query()->withoutGlobalScopes()
        ->where('vehicle_id', $vehicle->id)->first()->company_id)
        ->toBe($this->company->id);
});

// ---------------------------------------------------------------------------
// Tenancy — new records respect company isolation
// ---------------------------------------------------------------------------

it('cannot reach another company daily assignment', function (): void {
    $other = Company::factory()->create();
    $vehicle = Vehicle::factory()->create(['company_id' => $other->id]);
    $assignment = VehicleDailyAssignment::factory()->create([
        'vehicle_id' => $vehicle->id,
        'company_id' => $other->id,
    ]);

    $this->delete("/vehicles/{$vehicle->id}/daily-assignments/{$assignment->id}")->assertNotFound();
});

it('cannot reach another company fine', function (): void {
    $other = Company::factory()->create();
    $vehicle = Vehicle::factory()->create(['company_id' => $other->id]);
    $fine = VehicleFine::factory()->create([
        'vehicle_id' => $vehicle->id,
        'company_id' => $other->id,
    ]);

    $this->delete("/vehicles/{$vehicle->id}/fines/{$fine->id}")->assertNotFound();
});

it('cannot reach another company fuel record', function (): void {
    $other = Company::factory()->create();
    $vehicle = Vehicle::factory()->create(['company_id' => $other->id]);
    $record = VehicleFuelRecord::factory()->create([
        'vehicle_id' => $vehicle->id,
        'company_id' => $other->id,
    ]);

    $this->delete("/vehicles/{$vehicle->id}/fuel/{$record->id}")->assertNotFound();
});

// ---------------------------------------------------------------------------
// Permissions — new endpoints are gated
// ---------------------------------------------------------------------------

it('denies daily assignment creation to a user without vehicles.edit', function (): void {
    $plain = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);

    $this->actingAs($plain)
        ->post("/vehicles/{$vehicle->id}/daily-assignments", [
            'assigned_date' => '2026-07-28',
        ])->assertForbidden();
});

it('denies fine logging to a user without vehicles.edit', function (): void {
    $plain = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);

    $this->actingAs($plain)
        ->post("/vehicles/{$vehicle->id}/fines", [
            'fine_date' => '2026-07-15',
            'amount' => '200',
            'description' => 'Test',
            'charged_to' => 'company',
        ])->assertForbidden();
});

it('denies fuel logging to a user without vehicles.edit', function (): void {
    $plain = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);

    $this->actingAs($plain)
        ->post("/vehicles/{$vehicle->id}/fuel", [
            'fuel_date' => '2026-07-20',
            'litres' => '40',
            'cost_per_litre' => '1.6',
            'total_cost' => '64',
        ])->assertForbidden();
});
