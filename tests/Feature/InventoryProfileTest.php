<?php

use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Enums\WageType;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\Inventory\PpeComplianceService;
use App\Services\Inventory\StockMovementService;
use Inertia\Testing\AssertableInertia as Assert;

/** Issue an item to an employee, optionally with a PPE expiry, and return the issue. */
function issueTo(Employee $employee, EquipmentItem $item, StockMovementService $stock, ?string $expiry = null): void
{
    $stock->record($item, StockMovementType::StockIn, 5);
    $stock->issueTo($item->fresh(), array_filter([
        'employee_id' => $employee->id,
        'issued_quantity' => 1,
        'expiry_date' => $expiry,
    ]));
}

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->stock = app(StockMovementService::class);
});

// ── C1 — Employee Detail equipment tab ──────────────────────────────────────
it('shows a worker current and returned equipment on the employee detail tab', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create();
    $item = EquipmentItem::factory()->create(['company_id' => $this->company->id, 'name' => 'Casco', 'serial_number' => 'H-1']);
    $this->stock->record($item, StockMovementType::StockIn, 5);
    $issue = $this->stock->issueTo($item->fresh(), ['employee_id' => $employee->id, 'issued_quantity' => 2]);

    $this->actingAs($this->admin)->get("/employees/{$employee->id}")
        ->assertInertia(fn (Assert $p) => $p
            ->where('equipmentTab.count', 1)
            ->has('equipmentTab.current', 1)
            ->where('equipmentTab.current.0.item', 'Casco')
            ->where('equipmentTab.current.0.serial', 'H-1'));

    // Return it → moves from current to history.
    $this->stock->returnFrom($issue, 2);
    $this->actingAs($this->admin)->get("/employees/{$employee->id}")
        ->assertInertia(fn (Assert $p) => $p
            ->where('equipmentTab.count', 0)
            ->has('equipmentTab.history', 1));
});

it('hides the equipment tab from a user without inventory.view', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create();
    $user = User::factory()->forCompany($this->company)->create(['role' => UserRole::Manager]);
    // Can see the employee page, but NOT inventory → the tab is withheld.
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id, 'module' => 'employees', 'can_view' => true,
    ]);

    $this->actingAs($user)->get("/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('equipmentTab', null));
});

// ── D2 — PPE compliance ─────────────────────────────────────────────────────
it('reports missing, valid and expired PPE per required category', function (): void {
    $ppe = app(PpeComplianceService::class);
    $casco = EquipmentCategory::query()->create(['company_id' => $this->company->id, 'name' => 'Casco', 'is_required_ppe' => true, 'active' => true]);
    $guantes = EquipmentCategory::query()->create(['company_id' => $this->company->id, 'name' => 'Guantes', 'is_required_ppe' => true, 'active' => true]);

    $worker = Employee::factory()->forCompany($this->company)->create();
    // Holds a valid casco (future expiry), holds an EXPIRED pair of guantes.
    $cascoItem = EquipmentItem::factory()->create(['company_id' => $this->company->id, 'equipment_category_id' => $casco->id, 'is_ppe' => true]);
    $guantesItem = EquipmentItem::factory()->create(['company_id' => $this->company->id, 'equipment_category_id' => $guantes->id, 'is_ppe' => true]);
    issueTo($worker, $cascoItem, $this->stock, now()->addYear()->toDateString());
    issueTo($worker, $guantesItem, $this->stock, now()->subDay()->toDateString());

    $rows = collect($ppe->forEmployee($worker->fresh()))->keyBy('category');
    expect($rows['Casco']['status'])->toBe('valid')
        ->and($rows['Guantes']['status'])->toBe('expired');

    // A worker holding nothing → both missing.
    $bare = Employee::factory()->forCompany($this->company)->create();
    $bareRows = collect($ppe->forEmployee($bare))->pluck('status', 'category');
    expect($bareRows['Casco'])->toBe('missing')->and($bareRows['Guantes'])->toBe('missing');
});

it('requires a height-only category (arnés) only for a height worker', function (): void {
    $ppe = app(PpeComplianceService::class);
    EquipmentCategory::query()->create(['company_id' => $this->company->id, 'name' => 'Arnés', 'is_required_ppe' => true, 'height_only' => true, 'active' => true]);

    $ground = Employee::factory()->forCompany($this->company)->create(['works_at_height' => false]);
    $climber = Employee::factory()->forCompany($this->company)->create(['works_at_height' => true]);

    expect(collect($ppe->forEmployee($ground))->pluck('category'))->not->toContain('Arnés')
        ->and(collect($ppe->forEmployee($climber))->pluck('category'))->toContain('Arnés');
});

// ── C2 — Worker PWA "My equipment" (read-only, no money) ────────────────────
it('shows the worker their current equipment in the PWA, with no cost figure', function (): void {
    $user = User::factory()->worker()->for($this->company)->create();
    $employee = Employee::factory()->for($this->company)->create(['user_id' => $user->id, 'wage_type' => WageType::Daily]);
    $item = EquipmentItem::factory()->create(['company_id' => $this->company->id, 'name' => 'Taladro', 'serial_number' => 'DR-9']);
    $this->stock->record($item, StockMovementType::StockIn, 3);
    $this->stock->issueTo($item->fresh(), ['employee_id' => $employee->id, 'issued_quantity' => 1]);

    $this->actingAs($user)->get('/worker')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->has('equipment', 1)
            ->where('equipment.0.item', 'Taladro')
            ->where('equipment.0.serial', 'DR-9')
            ->missing('equipment.0.amount')
            ->missing('equipment.0.total')
            ->missing('equipment.0.cost')
            ->etc());
});
