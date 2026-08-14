<?php

use App\Enums\StockMovementType;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Services\Inventory\StockMovementService;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
    $this->stock = app(StockMovementService::class);
});

/** Count this admin's notifications of a given type. */
function alertCount(User $admin, string $type): int
{
    return $admin->fresh()->notifications()->where('data->type', $type)->count();
}

it('alerts admins about low stock once, and re-arms after recovery', function (): void {
    $item = EquipmentItem::factory()->create(['company_id' => $this->company->id, 'name' => 'Casco', 'minimum_stock' => '5', 'unit' => 'pcs']);
    $this->stock->record($item, StockMovementType::StockIn, 3); // 3 <= 5 → low

    $this->artisan('notifications:scan')->assertSuccessful();
    expect(alertCount($this->admin, 'inventory_low_stock'))->toBe(1);

    // Second sweep: guarded, no re-alert.
    $this->artisan('notifications:scan')->assertSuccessful();
    expect(alertCount($this->admin, 'inventory_low_stock'))->toBe(1);

    // Recover above minimum → the movement service re-arms the flag.
    $this->stock->record($item->fresh(), StockMovementType::StockIn, 10); // 13 > 5
    expect($item->fresh()->low_stock_notified_at)->toBeNull();
});

it('alerts admins once about kit not returned by its expected date', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create(['full_name' => 'Carlos']);
    $item = EquipmentItem::factory()->create(['company_id' => $this->company->id, 'name' => 'Taladro']);
    EmployeeEquipmentIssue::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id, 'equipment_item_id' => $item->id,
        'expected_return_date' => now()->subWeek()->toDateString(), 'status' => 'open',
    ]);

    $this->artisan('notifications:scan')->assertSuccessful();
    $this->artisan('notifications:scan')->assertSuccessful();
    expect(alertCount($this->admin, 'equipment_overdue'))->toBe(1);
});

it('alerts admins about PPE expiring within 30 days', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create(['full_name' => 'Ahmad']);
    $item = EquipmentItem::factory()->create(['company_id' => $this->company->id, 'name' => 'Guantes', 'is_ppe' => true]);
    EmployeeEquipmentIssue::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $employee->id, 'equipment_item_id' => $item->id,
        'expiry_date' => now()->addDays(10)->toDateString(), 'status' => 'open',
    ]);

    $this->artisan('notifications:scan')->assertSuccessful();
    expect(alertCount($this->admin, 'ppe_expiring'))->toBe(1);
});

it('sends a per-company summary when active workers miss the company own required PPE', function (): void {
    // Own-company required PPE (opt-in) + an active worker holding none.
    EquipmentCategory::query()->create(['company_id' => $this->company->id, 'name' => 'Botas', 'is_required_ppe' => true, 'active' => true]);
    Employee::factory()->forCompany($this->company)->create(['active' => true]);

    $this->artisan('notifications:scan')->assertSuccessful();
    expect(alertCount($this->admin, 'ppe_missing'))->toBe(1);
});

it('does not send a missing-PPE alert for the seeded group defaults alone', function (): void {
    // A company relying only on the shared defaults (no own required category)
    // gets NO proactive alert — the report covers it instead.
    Employee::factory()->forCompany($this->company)->create(['active' => true]);

    $this->artisan('notifications:scan')->assertSuccessful();
    expect(alertCount($this->admin, 'ppe_missing'))->toBe(0);
});
