<?php

use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Enums\WageType;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\Inventory\StockMovementService;
use Inertia\Testing\AssertableInertia as Assert;

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
