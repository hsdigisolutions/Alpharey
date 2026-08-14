<?php

use App\Enums\EquipmentAssignmentStatus;
use App\Enums\EquipmentIssueStatus;
use App\Enums\EquipmentItemType;
use App\Enums\EquipmentReturnCondition;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EquipmentCategory;
use App\Models\EquipmentIncident;
use App\Models\EquipmentItem;
use App\Models\EquipmentProjectAssignment;
use App\Models\EquipmentStockMovement;
use App\Models\Project;
use App\Models\User;
use App\Services\Inventory\StockMovementService;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'company_id' => $this->company->id,
    ]);
    $this->actingAs($this->admin);

    $this->item = EquipmentItem::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Casco de seguridad',
        'item_type' => EquipmentItemType::Safety,
    ]);
    $this->stock = app(StockMovementService::class);
});

/**
 * The ledger arithmetic. total_stock is everything owned; available_stock is
 * what is in the store — so total - available is what is out with workers.
 */
it('adds stock in and moves both counters', function (): void {
    $this->stock->record($this->item, StockMovementType::StockIn, 10);

    $item = $this->item->fresh();

    expect((float) $item->total_stock)->toBe(10.0)
        ->and((float) $item->available_stock)->toBe(10.0);
});

it('takes an issue out of available but not out of total — it is still owned', function (): void {
    $this->stock->record($this->item, StockMovementType::StockIn, 10);
    $this->stock->record($this->item->fresh(), StockMovementType::Issue, 3);

    $item = $this->item->fresh();

    expect((float) $item->total_stock)->toBe(10.0)
        ->and((float) $item->available_stock)->toBe(7.0);
});

it('puts a return back into available', function (): void {
    $this->stock->record($this->item, StockMovementType::StockIn, 10);
    $this->stock->record($this->item->fresh(), StockMovementType::Issue, 3);
    $this->stock->record($this->item->fresh(), StockMovementType::Return, 3);

    expect((float) $this->item->fresh()->available_stock)->toBe(10.0);
});

it('writes damaged stock off both counters', function (): void {
    $this->stock->record($this->item, StockMovementType::StockIn, 10);
    $this->stock->record($this->item->fresh(), StockMovementType::Damaged, 2);

    $item = $this->item->fresh();

    expect((float) $item->total_stock)->toBe(8.0)
        ->and((float) $item->available_stock)->toBe(8.0);
});

/**
 * A recount counts the STORE. It must not silently rewrite what is out with
 * workers — so the total moves by the same delta, and issued survives.
 */
it('adjusts the store without rewriting what is out with workers', function (): void {
    $this->stock->record($this->item, StockMovementType::StockIn, 10);
    $this->stock->record($this->item->fresh(), StockMovementType::Issue, 3); // 3 out, 7 in store

    // A recount finds only 6 in the store (one walked off).
    $this->stock->record($this->item->fresh(), StockMovementType::Adjustment, 6);

    $item = $this->item->fresh();

    expect((float) $item->available_stock)->toBe(6.0)
        // total drops by the same 1, so "issued" is still 3 — not 4
        ->and((float) $item->total_stock)->toBe(9.0)
        ->and(round((float) $item->total_stock - (float) $item->available_stock, 2))->toBe(3.0);
});

it('records the running balance on every movement', function (): void {
    $this->stock->record($this->item, StockMovementType::StockIn, 10);
    $this->stock->record($this->item->fresh(), StockMovementType::Issue, 4);
    $this->stock->record($this->item->fresh(), StockMovementType::Return, 1);

    $balances = EquipmentStockMovement::query()
        ->where('equipment_item_id', $this->item->id)
        ->orderBy('id')
        ->pluck('balance_after')
        ->map(fn ($b): float => (float) $b)
        ->all();

    // The balance is frozen per movement, so the history reconstructs itself.
    expect($balances)->toBe([10.0, 6.0, 7.0]);
});

it('refuses to issue more than is available', function (): void {
    $this->stock->record($this->item, StockMovementType::StockIn, 2);

    expect(fn () => $this->stock->record($this->item->fresh(), StockMovementType::Issue, 5))
        ->toThrow(ValidationException::class);

    // and nothing moved
    expect((float) $this->item->fresh()->available_stock)->toBe(2.0)
        ->and(EquipmentStockMovement::query()->count())->toBe(1);
});

it('refuses a zero or negative quantity', function (): void {
    expect(fn () => $this->stock->record($this->item, StockMovementType::StockIn, 0))
        ->toThrow(ValidationException::class);
});

/**
 * Issues to employees.
 */
it('issues kit to a worker and takes it out of the store in one go', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $this->stock->record($this->item, StockMovementType::StockIn, 10);

    $issue = $this->stock->issueTo($this->item->fresh(), [
        'employee_id' => $employee->id,
        'issued_quantity' => 2,
        'issue_date' => now()->toDateString(),
    ]);

    expect($issue->status)->toBe(EquipmentIssueStatus::Open)
        ->and((float) $this->item->fresh()->available_stock)->toBe(8.0);
});

it('keeps a partial return open until the rest comes back', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $this->stock->record($this->item, StockMovementType::StockIn, 10);

    $issue = $this->stock->issueTo($this->item->fresh(), [
        'employee_id' => $employee->id,
        'issued_quantity' => 5,
    ]);

    $issue = $this->stock->returnFrom($issue, 3);

    expect($issue->status)->toBe(EquipmentIssueStatus::PartiallyReturned)
        ->and($issue->outstanding())->toBe(2.0)
        // a partial return has no return date — it is not finished
        ->and($issue->return_date)->toBeNull()
        ->and((float) $this->item->fresh()->available_stock)->toBe(8.0);

    $issue = $this->stock->returnFrom($issue, 2);

    expect($issue->status)->toBe(EquipmentIssueStatus::Returned)
        ->and($issue->outstanding())->toBe(0.0)
        ->and($issue->return_date)->not->toBeNull()
        ->and((float) $this->item->fresh()->available_stock)->toBe(10.0);
});

it('refuses to take back more than went out', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $this->stock->record($this->item, StockMovementType::StockIn, 10);

    $issue = $this->stock->issueTo($this->item->fresh(), [
        'employee_id' => $employee->id,
        'issued_quantity' => 2,
    ]);

    expect(fn () => $this->stock->returnFrom($issue, 5))->toThrow(ValidationException::class);
});

it('flags an issue that is past its expected return', function (): void {
    $issue = EmployeeEquipmentIssue::factory()->create([
        'company_id' => $this->company->id,
        'equipment_item_id' => $this->item->id,
        'expected_return_date' => now()->subWeek()->toDateString(),
    ]);

    expect($issue->isOverdue())->toBeTrue();
});

it('never calls a returned issue overdue, however late it was', function (): void {
    $issue = EmployeeEquipmentIssue::factory()->create([
        'company_id' => $this->company->id,
        'equipment_item_id' => $this->item->id,
        'expected_return_date' => now()->subYear()->toDateString(),
    ]);
    $issue->status = EquipmentIssueStatus::Returned;
    $issue->save();

    expect($issue->isOverdue())->toBeFalse();
});

/**
 * Low stock: a minimum of 0 means "not tracked", not "always low".
 */
it('flags low stock only when a minimum is set', function (): void {
    $tracked = EquipmentItem::factory()->create(['company_id' => $this->company->id, 'minimum_stock' => '5']);
    $untracked = EquipmentItem::factory()->create(['company_id' => $this->company->id, 'minimum_stock' => '0']);

    app(StockMovementService::class)->record($tracked, StockMovementType::StockIn, 3);

    expect($tracked->fresh()->isLowStock())->toBeTrue()
        ->and($untracked->fresh()->isLowStock())->toBeFalse();
});

/**
 * Opening stock is a movement, not a column write.
 */
it('records opening stock as a stock_in movement', function (): void {
    $this->post('/inventory/items', [
        'name' => 'Taladro',
        'sku' => 'SKU-9999',
        'item_type' => EquipmentItemType::Tool->value,
        'unit' => 'pcs',
        'opening_stock' => 4,
    ])->assertRedirect();

    $item = EquipmentItem::query()->where('sku', 'SKU-9999')->first();

    expect((float) $item->available_stock)->toBe(4.0)
        // the ledger has to explain where every unit came from
        ->and(EquipmentStockMovement::query()->where('equipment_item_id', $item->id)
            ->where('movement_type', StockMovementType::StockIn->value)->count())->toBe(1);
});

it('ignores stock counters submitted through the item form', function (): void {
    $this->post('/inventory/items', [
        'name' => 'Taladro',
        'sku' => 'SKU-8888',
        'item_type' => EquipmentItemType::Tool->value,
        'unit' => 'pcs',
        'total_stock' => '999',      // the ledger owns these —
        'available_stock' => '999',  // a form must never set them
    ])->assertRedirect();

    $item = EquipmentItem::query()->where('sku', 'SKU-8888')->first();

    expect((float) $item->total_stock)->toBe(0.0)
        ->and((float) $item->available_stock)->toBe(0.0);
});

/**
 * Tenancy + permissions (Rule 11).
 */
it('shows a user only the stock of their own company', function (): void {
    $other = Company::factory()->create();
    EquipmentItem::factory()->create(['company_id' => $other->id]);

    expect(EquipmentItem::query()->count())->toBe(1); // only the beforeEach item
});

it('cannot record a movement against another company item', function (): void {
    $other = Company::factory()->create();
    $foreign = EquipmentItem::factory()->create(['company_id' => $other->id]);

    $this->post("/inventory/items/{$foreign->id}/movements", [
        'movement_type' => StockMovementType::StockIn->value,
        'quantity' => 5,
    ])->assertNotFound();
});

it('ignores a company_id supplied in request input', function (): void {
    $other = Company::factory()->create();

    $this->post('/inventory/items', [
        'company_id' => $other->id, // malicious
        'name' => 'Guantes',
        'sku' => 'SKU-7777',
        'item_type' => EquipmentItemType::Safety->value,
        'unit' => 'pcs',
    ])->assertRedirect();

    expect(EquipmentItem::query()->withoutGlobalScopes()->where('sku', 'SKU-7777')->first()->company_id)
        ->toBe($this->company->id);
});

it('lets two companies use the same SKU but not one company twice', function (): void {
    $other = Company::factory()->create();
    EquipmentItem::factory()->create(['company_id' => $other->id, 'sku' => 'SKU-DUP']);

    $this->post('/inventory/items', [
        'name' => 'A', 'sku' => 'SKU-DUP', 'item_type' => EquipmentItemType::Tool->value, 'unit' => 'pcs',
    ])->assertSessionHasNoErrors();

    $this->post('/inventory/items', [
        'name' => 'B', 'sku' => 'SKU-DUP', 'item_type' => EquipmentItemType::Tool->value, 'unit' => 'pcs',
    ])->assertSessionHasErrors('sku');
});

it('refuses to issue kit to another company employee', function (): void {
    $other = Company::factory()->create();
    $foreign = Employee::factory()->create(['company_id' => $other->id]);
    $this->stock->record($this->item, StockMovementType::StockIn, 10);

    $this->post("/inventory/items/{$this->item->id}/issue", [
        'employee_id' => $foreign->id,
        'issued_quantity' => 1,
    ])->assertSessionHasErrors('employee_id');

    expect(EmployeeEquipmentIssue::query()->count())->toBe(0);
});

it('denies inventory actions to a user without the permission', function (): void {
    $plain = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);

    $this->actingAs($plain)->get('/inventory')->assertForbidden();
    $this->actingAs($plain)->post("/inventory/items/{$this->item->id}/movements", [
        'movement_type' => StockMovementType::StockIn->value,
        'quantity' => 1,
    ])->assertForbidden();
});

/**
 * Phase A2 — project assignment now moves stock through the ledger (Bug 1/2 fix).
 */
it('assigns kit to a project through the ledger, reducing available', function (): void {
    $project = Project::factory()->forCompany($this->company)->create();
    $this->stock->record($this->item, StockMovementType::StockIn, 10);

    $this->post("/inventory/items/{$this->item->id}/assign", [
        'project_id' => $project->id, 'quantity' => 4, 'start_date' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $assignment = EquipmentProjectAssignment::query()->firstOrFail();
    expect((float) $this->item->fresh()->available_stock)->toBe(6.0)   // out of the store
        ->and((float) $this->item->fresh()->total_stock)->toBe(10.0)   // still owned
        ->and($assignment->status)->toBe(EquipmentAssignmentStatus::Active)
        ->and($assignment->outstanding())->toBe(4.0);
});

it('returns kit from a project — partial keeps it active, full completes it', function (): void {
    $project = Project::factory()->forCompany($this->company)->create();
    $this->stock->record($this->item, StockMovementType::StockIn, 10);
    $assignment = $this->stock->assignToProject($this->item->fresh(), [
        'project_id' => $project->id, 'quantity' => 4, 'start_date' => now()->toDateString(),
    ]);

    // Partial return of 1 → available 7, still active.
    $this->post("/inventory/assignments/{$assignment->id}/return", ['returned_quantity' => 1])->assertRedirect();
    expect((float) $this->item->fresh()->available_stock)->toBe(7.0)
        ->and($assignment->fresh()->status)->toBe(EquipmentAssignmentStatus::Active);

    // Return the rest → available 10, completed with an end date.
    $this->post("/inventory/assignments/{$assignment->id}/return", ['returned_quantity' => 3])->assertRedirect();
    $assignment->refresh();
    expect((float) $this->item->fresh()->available_stock)->toBe(10.0)
        ->and($assignment->status)->toBe(EquipmentAssignmentStatus::Completed)
        ->and($assignment->end_date)->not->toBeNull();
});

it('cannot return another company project assignment (404)', function (): void {
    $other = Company::factory()->create();
    $foreignItem = EquipmentItem::factory()->create(['company_id' => $other->id]);
    $foreignProject = Project::factory()->forCompany($other)->create();
    $foreignAssignment = EquipmentProjectAssignment::factory()->create([
        'company_id' => $other->id, 'equipment_item_id' => $foreignItem->id, 'project_id' => $foreignProject->id,
    ]);

    $this->post("/inventory/assignments/{$foreignAssignment->id}/return", ['returned_quantity' => 1])->assertNotFound();
});

/**
 * Phase A3 — item soft-delete + outstanding-kit guard.
 */
it('soft-deletes an item, hiding it from lists but keeping all history', function (): void {
    $this->stock->record($this->item, StockMovementType::StockIn, 5);

    $this->delete("/inventory/items/{$this->item->id}")->assertRedirect()->assertSessionHas('success');

    expect(EquipmentItem::query()->count())->toBe(0)                     // hidden
        ->and(EquipmentItem::withTrashed()->count())->toBe(1)           // kept
        ->and(EquipmentStockMovement::query()->count())->toBe(1);       // ledger preserved
});

it('blocks deleting an item with kit still out with a worker', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $this->stock->record($this->item, StockMovementType::StockIn, 5);
    $this->stock->issueTo($this->item->fresh(), ['employee_id' => $employee->id, 'issued_quantity' => 2]);

    $this->delete("/inventory/items/{$this->item->id}")->assertRedirect()->assertSessionHas('error');

    expect(EquipmentItem::query()->whereKey($this->item->id)->exists())->toBeTrue(); // not deleted
});

it('blocks deleting an item with an active project assignment', function (): void {
    $project = Project::factory()->forCompany($this->company)->create();
    $this->stock->record($this->item, StockMovementType::StockIn, 5);
    $this->stock->assignToProject($this->item->fresh(), [
        'project_id' => $project->id, 'quantity' => 2, 'start_date' => now()->toDateString(),
    ]);

    $this->delete("/inventory/items/{$this->item->id}")->assertRedirect()->assertSessionHas('error');
    expect(EquipmentItem::query()->whereKey($this->item->id)->exists())->toBeTrue();
});

/**
 * Phase A4 — category edit + delete.
 */
it('updates and deletes a company category', function (): void {
    $category = EquipmentCategory::factory()->create(['company_id' => $this->company->id, 'name' => 'Old']);

    $this->put("/inventory/categories/{$category->id}", ['name' => 'New', 'active' => true])->assertRedirect();
    expect($category->fresh()->name)->toBe('New');

    $this->delete("/inventory/categories/{$category->id}")->assertRedirect()->assertSessionHas('success');
    expect(EquipmentCategory::query()->whereKey($category->id)->exists())->toBeFalse();
});

it('blocks deleting a category still used by an item', function (): void {
    $category = EquipmentCategory::factory()->create(['company_id' => $this->company->id]);
    EquipmentItem::factory()->create(['company_id' => $this->company->id, 'equipment_category_id' => $category->id]);

    $this->delete("/inventory/categories/{$category->id}")->assertRedirect()->assertSessionHas('error');
    expect(EquipmentCategory::query()->whereKey($category->id)->exists())->toBeTrue();
});

it('cannot edit a shared default or another company category (404)', function (): void {
    $shared = EquipmentCategory::factory()->create(['company_id' => null]);
    $other = Company::factory()->create();
    $foreign = EquipmentCategory::factory()->create(['company_id' => $other->id]);

    $this->put("/inventory/categories/{$shared->id}", ['name' => 'X'])->assertNotFound();
    $this->delete("/inventory/categories/{$foreign->id}")->assertNotFound();
});

/**
 * Phase A5 — movements-tab filter.
 */
/**
 * Phase B — serial numbers (one item record = one serialized unit).
 */
it('saves a serial number and ships it in the item row', function (): void {
    $this->post('/inventory/items', [
        'name' => 'Power Drill', 'sku' => 'SKU-DR1', 'serial_number' => 'DR-001',
        'item_type' => EquipmentItemType::Tool->value, 'unit' => 'pcs',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $item = EquipmentItem::query()->where('sku', 'SKU-DR1')->firstOrFail();
    expect($item->serial_number)->toBe('DR-001');

    $this->get('/inventory')->assertInertia(fn (Assert $page) => $page
        ->where('items.data', fn ($rows) => collect($rows)->firstWhere('serial_number', 'DR-001') !== null));
});

it('rejects a duplicate serial in the same company but allows it across companies', function (): void {
    EquipmentItem::factory()->create(['company_id' => $this->company->id, 'sku' => 'S-A', 'serial_number' => 'DR-001']);
    $other = Company::factory()->create();
    EquipmentItem::factory()->create(['company_id' => $other->id, 'sku' => 'S-B', 'serial_number' => 'DR-001']); // fine, other company

    // Same company, same serial → rejected.
    $this->post('/inventory/items', [
        'name' => 'Dup', 'sku' => 'S-C', 'serial_number' => 'DR-001',
        'item_type' => EquipmentItemType::Tool->value, 'unit' => 'pcs',
    ])->assertSessionHasErrors('serial_number');
});

it('carries the serial onto the worker issue row', function (): void {
    $serialItem = EquipmentItem::factory()->create(['company_id' => $this->company->id, 'serial_number' => 'DR-007']);
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $this->stock->record($serialItem, StockMovementType::StockIn, 1);
    $this->stock->issueTo($serialItem->fresh(), ['employee_id' => $employee->id, 'issued_quantity' => 1]);

    $this->get('/inventory')->assertInertia(fn (Assert $page) => $page
        ->where('issues', fn ($rows) => collect($rows)->firstWhere('serial', 'DR-007') !== null));
});

it('records consumable usage: both counters drop, and saves the consumable type', function (): void {
    $this->post('/inventory/items', [
        'name' => 'Cemento', 'sku' => 'CEM-1', 'item_type' => EquipmentItemType::Consumable->value, 'unit' => 'sacos',
        'opening_stock' => 50,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $item = EquipmentItem::query()->where('sku', 'CEM-1')->firstOrFail();
    expect($item->item_type)->toBe(EquipmentItemType::Consumable)
        ->and((float) $item->available_stock)->toBe(50.0);

    // Use 12 sacks — a Usage movement drops total AND available.
    $this->post("/inventory/items/{$item->id}/movements", [
        'movement_type' => StockMovementType::Usage->value, 'quantity' => 12,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $item->refresh();
    expect((float) $item->available_stock)->toBe(38.0)
        ->and((float) $item->total_stock)->toBe(38.0)
        ->and(EquipmentStockMovement::query()->where('equipment_item_id', $item->id)
            ->where('movement_type', StockMovementType::Usage->value)->count())->toBe(1);
});

it('writes off a damaged return: total drops, available unchanged, incident logged', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $this->stock->record($this->item, StockMovementType::StockIn, 10);
    $issue = $this->stock->issueTo($this->item->fresh(), ['employee_id' => $employee->id, 'issued_quantity' => 4]);
    // Now: total 10, available 6 (4 out with the worker).

    $this->stock->returnFrom($issue, 4, EquipmentReturnCondition::Damaged, 'Dropped on site');

    $item = $this->item->fresh();
    // The 4 are gone: total 10 → 6; available stays 6 (they never re-entered the store).
    expect((float) $item->total_stock)->toBe(6.0)
        ->and((float) $item->available_stock)->toBe(6.0)
        ->and($issue->fresh()->status)->toBe(EquipmentIssueStatus::Returned);

    $incident = EquipmentIncident::withoutGlobalScopes()->firstOrFail();
    expect($incident->condition)->toBe(EquipmentReturnCondition::Damaged)
        ->and((float) $incident->quantity)->toBe(4.0)
        ->and($incident->employee_id)->toBe($employee->id)
        ->and($incident->notes)->toBe('Dropped on site');
});

it('a good return restores the store and logs no incident', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $this->stock->record($this->item, StockMovementType::StockIn, 10);
    $issue = $this->stock->issueTo($this->item->fresh(), ['employee_id' => $employee->id, 'issued_quantity' => 4]);

    $this->stock->returnFrom($issue, 4, EquipmentReturnCondition::Good);

    $item = $this->item->fresh();
    expect((float) $item->total_stock)->toBe(10.0)
        ->and((float) $item->available_stock)->toBe(10.0)
        ->and(EquipmentIncident::withoutGlobalScopes()->count())->toBe(0);
});

it('requires a note for a damaged or lost return', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $this->stock->record($this->item, StockMovementType::StockIn, 5);
    $issue = $this->stock->issueTo($this->item->fresh(), ['employee_id' => $employee->id, 'issued_quantity' => 1]);

    $this->post("/inventory/issues/{$issue->id}/return", [
        'returned_quantity' => 1, 'condition' => 'damaged',
    ])->assertSessionHasErrors('notes');

    // A plain good return needs no note.
    $this->post("/inventory/issues/{$issue->id}/return", [
        'returned_quantity' => 1, 'condition' => 'good',
    ])->assertSessionHasNoErrors();
});

it('saves the PPE flag + default expiry on an item and the expiry on an issue', function (): void {
    $this->post('/inventory/items', [
        'name' => 'Arnés', 'sku' => 'ARN-1', 'item_type' => EquipmentItemType::Safety->value, 'unit' => 'pcs',
        'is_ppe' => true, 'default_expiry_date' => '2027-01-01',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $item = EquipmentItem::query()->where('sku', 'ARN-1')->firstOrFail();
    expect($item->is_ppe)->toBeTrue()
        ->and($item->default_expiry_date->toDateString())->toBe('2027-01-01');

    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $this->stock->record($item, StockMovementType::StockIn, 3);
    $this->post("/inventory/items/{$item->id}/issue", [
        'employee_id' => $employee->id, 'issued_quantity' => 1, 'expiry_date' => '2027-01-01',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $issue = EmployeeEquipmentIssue::query()->where('employee_id', $employee->id)->firstOrFail();
    expect($issue->expiry_date->toDateString())->toBe('2027-01-01');
});

it('filters the stock-movements ledger by item', function (): void {
    $other = EquipmentItem::factory()->create(['company_id' => $this->company->id, 'name' => 'Taladro']);
    $this->stock->record($this->item, StockMovementType::StockIn, 5);
    $this->stock->record($other, StockMovementType::StockIn, 3);

    $this->get("/inventory?mv_item={$this->item->id}")
        ->assertInertia(fn (Assert $page) => $page->has('movements', 1)
            ->where('movements.0.item', 'Casco de seguridad'));
});
