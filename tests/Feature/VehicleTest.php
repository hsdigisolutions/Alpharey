<?php

use App\Enums\UserRole;
use App\Enums\VehicleOwnership;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleHistory;
use App\Models\VehicleMaintenanceHistory;
use App\Notifications\DocumentAlertNotification;
use App\Services\Vehicles\VehicleCompliance;
use App\Services\Vehicles\VehicleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'company_id' => $this->company->id,
    ]);
    $this->actingAs($this->admin);
});

/**
 * The compliance integration — the reason vehicles are in this phase at all.
 */
it('grades insurance and ITV on the documents traffic light', function (): void {
    $vehicle = Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'insurance_expiry_date' => now()->addDays(15)->toDateString(),  // inside 90 -> warn
        'ita_expiry_date' => now()->subDay()->toDateString(),           // past -> danger
    ]);

    $compliance = app(VehicleCompliance::class);

    expect($compliance->of($vehicle, 'insurance')[0])->toBe('warn')
        ->and($compliance->of($vehicle, 'ita')[0])->toBe('danger')
        // the row indicator takes the worst of the two
        ->and($compliance->worst($vehicle))->toBe('danger');
});

it('treats an unknown expiry as a gap, not as compliant', function (): void {
    $vehicle = Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'insurance_expiry_date' => null,
        'ita_expiry_date' => null,
    ]);

    // 'neutral', never 'ok': we do not know that this van is insured.
    expect(app(VehicleCompliance::class)->of($vehicle, 'insurance')[0])->toBe('neutral')
        ->and(app(VehicleCompliance::class)->worst($vehicle))->toBe('neutral');
});

it('is comfortably ok when all three expiries are far out', function (): void {
    $vehicle = Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'insurance_expiry_date' => now()->addDays(200)->toDateString(),
        'ita_expiry_date' => now()->addDays(200)->toDateString(),
        'road_tax_expiry_date' => now()->addDays(200)->toDateString(),
    ]);

    expect(app(VehicleCompliance::class)->worst($vehicle))->toBe('ok');
});

/**
 * The claim in DEVELOPMENT_PLAN Phase 7: "insurance/ITV expiries flow into
 * the documents/compliance alert system". Not a status on a screen — an
 * actual alert, on the confirmed 90/60/30 schedule.
 */
it('alerts company admins on the 90/60/30 milestones like any document', function (): void {
    Notification::fake();

    Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'plate_number' => '1234ABC',
        'insurance_expiry_date' => now()->addDays(60)->toDateString(),
        'ita_expiry_date' => null,
    ]);

    $this->artisan('verto:scan-documents')->assertSuccessful();

    Notification::assertSentTo($this->admin, DocumentAlertNotification::class);
});

it('alerts on the expiry day itself', function (): void {
    Notification::fake();

    Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'ita_expiry_date' => now()->toDateString(),
        'insurance_expiry_date' => null,
    ]);

    $this->artisan('verto:scan-documents')->assertSuccessful();

    Notification::assertSentTo($this->admin, DocumentAlertNotification::class);
});

it('stays quiet on a day that is not a milestone', function (): void {
    Notification::fake();
    // Pin to the 15th so the scan's first-of-month monthly sweep does not fire
    // and the 45-day expiry is not a 30/60/90-day milestone from this date.
    Carbon::setTestNow(now()->setDay(15));

    Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'insurance_expiry_date' => now()->addDays(45)->toDateString(), // not 90/60/30/0
        'ita_expiry_date' => null,
    ]);

    $this->artisan('verto:scan-documents')->assertSuccessful();

    Notification::assertNothingSent();
    Carbon::setTestNow();
});

it('does not chase an inactive vehicle', function (): void {
    Notification::fake();
    Carbon::setTestNow(now()->setDay(15));

    Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'active' => false,
        'insurance_expiry_date' => now()->addDays(30)->toDateString(),
        'ita_expiry_date' => null,
    ]);

    $this->artisan('verto:scan-documents')->assertSuccessful();

    Notification::assertNothingSent();
    Carbon::setTestNow();
});

/**
 * Assignment history — tab 2.
 */
it('closes the previous assignment when the vehicle changes hands', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $first = Employee::factory()->create(['company_id' => $this->company->id]);
    $second = Employee::factory()->create(['company_id' => $this->company->id]);
    $service = app(VehicleService::class);

    $service->assign($vehicle, $first->id);
    $service->assign($vehicle->fresh(), $second->id);

    $open = VehicleHistory::query()->where('vehicle_id', $vehicle->id)->whereNull('assigned_to')->get();

    // exactly one driver at a time
    expect(VehicleHistory::query()->where('vehicle_id', $vehicle->id)->count())->toBe(2)
        ->and($open)->toHaveCount(1)
        ->and($open->first()->employee_id)->toBe($second->id)
        ->and($vehicle->fresh()->assigned_employee_id)->toBe($second->id);
});

it('writes assignment history when the driver is changed through the edit form', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);

    $this->put("/vehicles/{$vehicle->id}", [
        'plate_number' => $vehicle->plate_number,
        'ownership' => VehicleOwnership::Company->value,
        'assigned_employee_id' => $employee->id,
    ])->assertRedirect();

    // Editing the field must not bypass tab 2.
    expect(VehicleHistory::query()->where('vehicle_id', $vehicle->id)->count())->toBe(1)
        ->and($vehicle->fresh()->assigned_employee_id)->toBe($employee->id);
});

it('releases the vehicle back to the pool', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $service = app(VehicleService::class);

    $service->assign($vehicle, $employee->id);
    $service->assign($vehicle->fresh(), null);

    expect($vehicle->fresh()->assigned_employee_id)->toBeNull()
        ->and(VehicleHistory::query()->whereNull('assigned_to')->count())->toBe(0);
});

/**
 * Maintenance + mileage — tabs 3 and 4.
 */
it('rolls the maintenance log up into the vehicle total', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $service = app(VehicleService::class);

    $service->logMaintenance($vehicle, ['maintenance_type' => 'oil_change', 'maintenance_date' => now()->toDateString(), 'cost' => '120']);
    $service->logMaintenance($vehicle->fresh(), ['maintenance_type' => 'tyres', 'maintenance_date' => now()->toDateString(), 'cost' => '300']);

    expect((float) $vehicle->fresh()->maintenance_cost_total)->toBe(420.0);
});

it('recomputes the total when a maintenance record is removed', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $service = app(VehicleService::class);

    $first = $service->logMaintenance($vehicle, ['maintenance_type' => 'oil_change', 'maintenance_date' => now()->toDateString(), 'cost' => '120']);
    $service->logMaintenance($vehicle->fresh(), ['maintenance_type' => 'tyres', 'maintenance_date' => now()->toDateString(), 'cost' => '300']);

    $service->deleteMaintenance($first);

    // recomputed from the log, not decremented
    expect((float) $vehicle->fresh()->maintenance_cost_total)->toBe(300.0);
});

it('records a mileage reading and moves the odometer', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id, 'current_mileage' => 50000]);

    app(VehicleService::class)->logMileage($vehicle, 51000);

    expect($vehicle->fresh()->current_mileage)->toBe(51000);
});

it('refuses a mileage reading that runs the odometer backwards', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id, 'current_mileage' => 50000]);

    expect(fn () => app(VehicleService::class)->logMileage($vehicle, 49000))
        ->toThrow(ValidationException::class);

    expect($vehicle->fresh()->current_mileage)->toBe(50000);
});

it('reports when the next oil change is due', function (): void {
    $vehicle = Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'last_oil_change_mileage' => 50000,
        'oil_change_interval_km' => 15000,
    ]);

    expect($vehicle->oilChangeDueAt())->toBe(65000);
});

it('has no oil change due date without an interval', function (): void {
    $vehicle = Vehicle::factory()->create([
        'company_id' => $this->company->id,
        'oil_change_interval_km' => null,
    ]);

    expect($vehicle->oilChangeDueAt())->toBeNull();
});

/**
 * Tenancy + permissions (Rule 11).
 */
it('shows a user only the vehicles of their own company', function (): void {
    $other = Company::factory()->create();
    Vehicle::factory()->create(['company_id' => $this->company->id]);
    Vehicle::factory()->create(['company_id' => $other->id]);

    expect(Vehicle::query()->count())->toBe(1);
});

it('cannot reach another company vehicle by id', function (): void {
    $other = Company::factory()->create();
    $foreign = Vehicle::factory()->create(['company_id' => $other->id]);

    $this->get("/vehicles/{$foreign->id}")->assertNotFound();
});

it('ignores a company_id supplied in request input', function (): void {
    $other = Company::factory()->create();

    $this->post('/vehicles', [
        'company_id' => $other->id, // malicious
        'plate_number' => '9999ZZZ',
        'ownership' => VehicleOwnership::Company->value,
    ])->assertRedirect();

    expect(Vehicle::query()->withoutGlobalScopes()->where('plate_number', '9999ZZZ')->first()->company_id)
        ->toBe($this->company->id);
});

it('lets two companies register the same plate but not one company twice', function (): void {
    $other = Company::factory()->create();
    Vehicle::factory()->create(['company_id' => $other->id, 'plate_number' => '1234ABC']);

    // another company's plate is not a clash
    $this->post('/vehicles', [
        'plate_number' => '1234ABC',
        'ownership' => VehicleOwnership::Company->value,
    ])->assertSessionHasNoErrors();

    // ...but our own is
    $this->post('/vehicles', [
        'plate_number' => '1234ABC',
        'ownership' => VehicleOwnership::Company->value,
    ])->assertSessionHasErrors('plate_number');
});

it('refuses to assign a vehicle to another company employee', function (): void {
    $other = Company::factory()->create();
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $foreign = Employee::factory()->create(['company_id' => $other->id]);

    $this->post("/vehicles/{$vehicle->id}/assign", ['employee_id' => $foreign->id])
        ->assertSessionHasErrors('employee_id');

    expect($vehicle->fresh()->assigned_employee_id)->toBeNull();
});

it('cannot delete a maintenance record through another vehicle', function (): void {
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $decoy = Vehicle::factory()->create(['company_id' => $this->company->id]);
    $record = VehicleMaintenanceHistory::factory()->create(['vehicle_id' => $vehicle->id]);

    $this->delete("/vehicles/{$decoy->id}/maintenance/{$record->id}")->assertNotFound();

    expect(VehicleMaintenanceHistory::query()->whereKey($record->id)->exists())->toBeTrue();
});

it('denies vehicle actions to a user without the permission', function (): void {
    $plain = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);
    $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);

    $this->actingAs($plain)->get('/vehicles')->assertForbidden();
    $this->actingAs($plain)->post("/vehicles/{$vehicle->id}/assign", ['employee_id' => null])->assertForbidden();
});

/**
 * Found in the browser, not by the suite: a Super Admin browsing "all
 * companies" has no active company, so company_id went null and the insert
 * blew up with a 500. The suite missed it for the reason CLAUDE.md decision 27
 * already records — a company admin always HAS a company, so every other test
 * here is blind to it.
 */
it('sends a super admin with no company selected to Welcome instead of failing', function (): void {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin, 'company_id' => null]);

    $this->actingAs($superAdmin)->post('/vehicles', [
        'plate_number' => '0000XXX',
        'ownership' => VehicleOwnership::Company->value,
    ])->assertRedirect('/welcome');

    expect(Vehicle::query()->withoutGlobalScopes()->where('plate_number', '0000XXX')->exists())->toBeFalse();
});

it('offers the create button to a Super Admin even with no company selected', function (): void {
    // The button is now permission-only (a support report: hiding it read as
    // "the feature is missing"). The Vue gate routes a company-less Super Admin
    // to the picker on click, and store() still redirects server-side — proven
    // by the test above. So the button IS offered.
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin, 'company_id' => null]);

    $this->actingAs($superAdmin)->get('/vehicles')
        ->assertInertia(fn ($page) => $page->where('can.create', true));
});
