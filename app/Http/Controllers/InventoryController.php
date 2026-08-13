<?php

namespace App\Http\Controllers;

use App\Enums\EquipmentAssignmentStatus;
use App\Enums\EquipmentIssueStatus;
use App\Enums\EquipmentItemType;
use App\Enums\StockMovementType;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Http\Requests\StoreEquipmentItemRequest;
use App\Models\Employee;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\EquipmentProjectAssignment;
use App\Models\EquipmentStockMovement;
use App\Models\Project;
use App\Services\Inventory\StockMovementService;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 23 — Inventory / Equipment. Company-owned.
 *
 * Every stock change goes through StockMovementService so the ledger and the
 * item counters can never disagree.
 */
class InventoryController extends Controller
{
    use ResolvesCompanyContext;

    public function __construct(private readonly StockMovementService $stock) {}

    public function index(Request $request): Response
    {
        Gate::authorize('inventory.view');

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        $items = $this->filteredQuery($request)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (EquipmentItem $i): array => $this->row($i));

        return Inertia::render('Inventory/Index', [
            'items' => $items,
            'filters' => $request->only(['search', 'equipment_category_id', 'item_type', 'active', 'low_stock', 'per_page', 'mv_item', 'mv_from', 'mv_to']),
            'categories' => $this->availableCategories(),
            // Lightweight item list for the movements-tab filter dropdown.
            'itemOptions' => EquipmentItem::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (EquipmentItem $i): array => ['id' => $i->id, 'name' => $i->name])->all(),
            'itemTypes' => array_map(fn (EquipmentItemType $t): string => $t->value, EquipmentItemType::cases()),
            'movementTypes' => array_map(fn (StockMovementType $t): string => $t->value, StockMovementType::cases()),
            'employees' => Employee::query()->orderBy('full_name')->get(['id', 'full_name']),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'movements' => $this->movements($request),
            'issues' => $this->issues($request),
            'assignments' => $this->assignments(),
            'can' => [
                'create' => Gate::allows('inventory.create'),
                'edit' => Gate::allows('inventory.edit'),
                'delete' => Gate::allows('inventory.delete'),
            ],
        ]);
    }

    public function store(StoreEquipmentItemRequest $request): RedirectResponse
    {
        $item = new EquipmentItem($request->safe()->except('opening_stock'));
        $item->company_id = $this->contextCompanyId();
        $item->save();

        // Opening stock is a stock_in movement, not a column write — the
        // ledger has to explain where every unit came from.
        if ($request->filled('opening_stock') && (float) $request->input('opening_stock') > 0) {
            $this->stock->record($item, StockMovementType::StockIn, (float) $request->input('opening_stock'), [
                'notes' => __('ui.inventory.opening_stock'),
            ]);
        }

        return back()->with('success', __('ui.inventory.item_saved'));
    }

    public function update(StoreEquipmentItemRequest $request, EquipmentItem $item): RedirectResponse
    {
        $item->update($request->safe()->except('opening_stock'));

        return back()->with('success', __('ui.inventory.item_saved'));
    }

    public function destroy(EquipmentItem $item): RedirectResponse
    {
        Gate::authorize('inventory.delete');

        // Guard: kit still out with a worker or on a site must be resolved first
        // (soft-delete keeps all ledger + issue history — decision Q7).
        $kitStillOut = $item->issues()->where('status', '!=', EquipmentIssueStatus::Returned->value)->exists()
            || $item->assignments()->where('status', EquipmentAssignmentStatus::Active->value)->exists();

        if ($kitStillOut) {
            return back()->with('error', __('ui.inventory.delete_blocked'));
        }

        $item->delete();

        return back()->with('success', __('ui.inventory.item_deleted'));
    }

    public function storeMovement(Request $request, EquipmentItem $item): RedirectResponse
    {
        Gate::authorize('inventory.edit');

        $validated = $request->validate([
            'movement_type' => ['required', Rule::enum(StockMovementType::class)],
            'quantity' => ['required', 'numeric'],
            'employee_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->stock->record(
            $item,
            StockMovementType::from($validated['movement_type']),
            (float) $validated['quantity'],
            $validated,
        );

        return back()->with('success', __('ui.inventory.movement_recorded'));
    }

    public function issue(Request $request, EquipmentItem $item): RedirectResponse
    {
        Gate::authorize('inventory.edit');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'issued_quantity' => ['required', 'numeric', 'min:0.01'],
            'issue_date' => ['nullable', 'date'],
            'expected_return_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // The global scope makes a foreign employee simply not exist (Rule 1).
        if (! Employee::query()->whereKey($validated['employee_id'])->exists()) {
            return back()->withErrors(['employee_id' => __('ui.inventory.employee_not_found')]);
        }

        $this->stock->issueTo($item, $validated);

        return back()->with('success', __('ui.inventory.issued'));
    }

    public function returnIssue(Request $request, EmployeeEquipmentIssue $issue): RedirectResponse
    {
        Gate::authorize('inventory.edit');

        $validated = $request->validate([
            'returned_quantity' => ['required', 'numeric', 'min:0.01'],
        ]);

        $this->stock->returnFrom($issue, (float) $validated['returned_quantity']);

        return back()->with('success', __('ui.inventory.returned'));
    }

    public function assignToProject(Request $request, EquipmentItem $item): RedirectResponse
    {
        Gate::authorize('inventory.edit');

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! Project::query()->whereKey($validated['project_id'])->exists()) {
            return back()->withErrors(['project_id' => __('ui.inventory.project_not_found')]);
        }

        // Through the ledger: assigning to a site takes the kit out of the store
        // (Bug 1 fix) — available_stock now reflects kit on projects too.
        $this->stock->assignToProject($item, $validated);

        return back()->with('success', __('ui.inventory.assigned'));
    }

    /** Take kit back from a site — partial returns supported (Bug 2 fix). */
    public function returnAssignment(Request $request, EquipmentProjectAssignment $assignment): RedirectResponse
    {
        Gate::authorize('inventory.edit');

        $validated = $request->validate([
            'returned_quantity' => ['required', 'numeric', 'min:0.01'],
        ]);

        $this->stock->returnFromProject($assignment, (float) $validated['returned_quantity']);

        return back()->with('success', __('ui.inventory.assignment_returned'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        Gate::authorize('inventory.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'active' => ['boolean'],
        ]);

        // A category created here belongs to this company — never to the
        // shared NULL pool, which is reference data.
        EquipmentCategory::query()->create(array_merge($validated, [
            'company_id' => $this->contextCompanyId(),
        ]));

        return back()->with('success', __('ui.inventory.category_saved'));
    }

    public function updateCategory(Request $request, EquipmentCategory $category): RedirectResponse
    {
        Gate::authorize('inventory.edit');

        // Only this company's own categories are editable — the shared NULL
        // defaults and other companies' categories are off limits.
        abort_unless($category->company_id === $this->contextCompanyId(), 404);

        $category->update($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'active' => ['boolean'],
        ]));

        return back()->with('success', __('ui.inventory.category_saved'));
    }

    public function destroyCategory(EquipmentCategory $category): RedirectResponse
    {
        Gate::authorize('inventory.delete');

        abort_unless($category->company_id === $this->contextCompanyId(), 404);

        // A category still used by an item cannot be deleted — deactivate it
        // instead (ExpenseCategory pattern).
        if ($category->items()->exists()) {
            return back()->with('error', __('ui.inventory.category_in_use'));
        }

        $category->delete();

        return back()->with('success', __('ui.inventory.category_deleted'));
    }

    /**
     * @return Builder<EquipmentItem>
     */
    private function filteredQuery(Request $request): Builder
    {
        return EquipmentItem::query()
            ->with('category:id,name')
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = '%'.$request->string('search')->value().'%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            ->when($request->filled('equipment_category_id'), fn (Builder $q) => $q->where('equipment_category_id', $request->integer('equipment_category_id')))
            ->when($request->filled('item_type'), fn (Builder $q) => $q->where('item_type', $request->string('item_type')->value()))
            ->when($request->filled('active'), fn (Builder $q) => $q->where('active', $request->boolean('active')))
            ->when($request->boolean('low_stock'), fn (Builder $q) => $q->whereColumn('available_stock', '<=', 'minimum_stock')->where('minimum_stock', '>', 0))
            ->orderBy('name');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(EquipmentItem $i): array
    {
        return [
            'id' => $i->id,
            'name' => $i->name,
            'sku' => $i->sku,
            'serial_number' => $i->serial_number,
            'category' => $i->category?->name,
            'equipment_category_id' => $i->equipment_category_id,
            'item_type' => $i->item_type->value,
            'unit' => $i->unit,
            'total_stock' => (float) $i->total_stock,
            'available_stock' => (float) $i->available_stock,
            // what is out with workers and sites — derived, never stored
            'issued_stock' => round((float) $i->total_stock - (float) $i->available_stock, 2),
            'minimum_stock' => (float) $i->minimum_stock,
            'low_stock' => $i->isLowStock(),
            'active' => $i->active,
            'notes' => $i->notes,
        ];
    }

    /**
     * The stock ledger with its running balance (Screen 23, movements table).
     *
     * @return array<int, array<string, mixed>>
     */
    private function movements(Request $request): array
    {
        return EquipmentStockMovement::query()
            ->with(['item:id,name,sku,serial_number,unit', 'employee:id,full_name', 'project:id,name'])
            ->when($request->filled('mv_item'), fn (Builder $q) => $q->where('equipment_item_id', $request->integer('mv_item')))
            ->when($request->filled('mv_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->string('mv_from')))
            ->when($request->filled('mv_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->string('mv_to')))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (EquipmentStockMovement $m): array => [
                'id' => $m->id,
                'item' => $m->item?->name,
                'serial' => $m->item?->serial_number,
                'movement_type' => $m->movement_type->value,
                'quantity' => (float) $m->quantity,
                'balance_after' => $m->balance_after !== null ? (float) $m->balance_after : null,
                'employee' => $m->employee?->full_name,
                'project' => $m->project?->name,
                'notes' => $m->notes,
                'created_at' => $m->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function issues(Request $request): array
    {
        return EmployeeEquipmentIssue::query()
            ->with(['item:id,name,sku,serial_number,unit', 'employee:id,full_name'])
            ->when($request->filled('issue_status'), fn (Builder $q) => $q->where('status', $request->string('issue_status')->value()))
            ->orderByDesc('issue_date')
            ->limit(100)
            ->get()
            ->map(fn (EmployeeEquipmentIssue $i): array => [
                'id' => $i->id,
                'employee' => $i->employee?->full_name,
                'item' => $i->item?->name,
                'serial' => $i->item?->serial_number,
                'issued_quantity' => (float) $i->issued_quantity,
                'returned_quantity' => (float) $i->returned_quantity,
                'outstanding' => $i->outstanding(),
                'issue_date' => $i->issue_date->toDateString(),
                'expected_return_date' => $i->expected_return_date?->toDateString(),
                'return_date' => $i->return_date?->toDateString(),
                'status' => $i->status->value,
                'overdue' => $i->isOverdue(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assignments(): array
    {
        return EquipmentProjectAssignment::query()
            ->with(['item:id,name,sku,serial_number', 'project:id,name'])
            ->orderByDesc('start_date')
            ->limit(100)
            ->get()
            ->map(fn (EquipmentProjectAssignment $a): array => [
                'id' => $a->id,
                'item' => $a->item?->name,
                'serial' => $a->item?->serial_number,
                'project' => $a->project?->name,
                'quantity' => (float) $a->quantity,
                'returned_quantity' => (float) $a->returned_quantity,
                'outstanding' => $a->outstanding(),
                'start_date' => $a->start_date->toDateString(),
                'end_date' => $a->end_date?->toDateString(),
                'status' => $a->status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableCategories(): array
    {
        // One grouped count, not one COUNT per category (N+1).
        $counts = EquipmentItem::query()
            ->whereNotNull('equipment_category_id')
            ->selectRaw('equipment_category_id, count(*) as total')
            ->groupBy('equipment_category_id')
            ->pluck('total', 'equipment_category_id');

        $companyId = app(CurrentCompany::class)->id();

        return EquipmentCategory::query()
            ->forCompany($companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'description', 'active'])
            ->map(fn (EquipmentCategory $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'active' => $c->active,
                'item_count' => (int) ($counts[$c->id] ?? 0),
                // Shared NULL defaults are read-only; only the company's own edit.
                'editable' => $c->company_id === $companyId,
            ])
            ->values()
            ->all();
    }
}
