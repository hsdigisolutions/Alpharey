<?php

namespace App\Http\Controllers;

use App\Enums\FuelType;
use App\Enums\VehicleOwnership;
use App\Enums\VehicleType;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Http\Requests\StoreDailyAssignmentRequest;
use App\Http\Requests\StoreFineRequest;
use App\Http\Requests\StoreFuelRequest;
use App\Http\Requests\StoreVehicleRequest;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleDailyAssignment;
use App\Models\VehicleFine;
use App\Models\VehicleFuelRecord;
use App\Models\VehicleMaintenanceHistory;
use App\Models\VehicleSession;
use App\Services\Vehicles\VehicleCompliance;
use App\Services\Vehicles\VehicleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 21 — Vehicles, all 4 tabs. Company-owned.
 *
 * The insurance/ITV traffic light comes from VehicleCompliance, which grades
 * against the same warn window as the documents system.
 */
class VehicleController extends Controller
{
    // A vehicle belongs to exactly one fleet, so creating one needs an active
    // company. A Super Admin browsing "all companies" is sent to Welcome to
    // pick one rather than hitting a null company_id (decision 27).
    use ResolvesCompanyContext;

    public function __construct(
        private readonly VehicleService $vehicles,
        private readonly VehicleCompliance $compliance,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('vehicles.view');

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        $vehicles = $this->filteredQuery($request)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Vehicle $v): array => $this->row($v));

        // Vehicle IDs scoped to this company (CompanyScope already applied on Vehicle)
        $vehicleIds = Vehicle::query()->pluck('id');

        $activeSessions = VehicleSession::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereNull('returned_at')
            ->with(['employee:id,full_name', 'vehicle:id,plate_number,brand,model'])
            ->orderBy('taken_at')
            ->get()
            ->map(fn (VehicleSession $s): array => [
                'id' => $s->id,
                'employee' => $s->employee?->full_name,
                'vehicle_id' => $s->vehicle_id,
                'plate_number' => $s->vehicle?->plate_number,
                'brand' => $s->vehicle?->brand,
                'model' => $s->vehicle?->model,
                'taken_at' => $s->taken_at->toIso8601ZuluString(),
                'starting_mileage' => $s->starting_mileage,
            ])
            ->values();

        $recentSessions = VehicleSession::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereNotNull('returned_at')
            ->with(['employee:id,full_name', 'vehicle:id,plate_number,brand,model'])
            ->orderBy('returned_at', 'desc')
            ->limit(30)
            ->get()
            ->map(fn (VehicleSession $s): array => [
                'id' => $s->id,
                'employee' => $s->employee?->full_name,
                'vehicle_id' => $s->vehicle_id,
                'plate_number' => $s->vehicle?->plate_number,
                'brand' => $s->vehicle?->brand,
                'model' => $s->vehicle?->model,
                'taken_at' => $s->taken_at->toIso8601ZuluString(),
                'returned_at' => $s->returned_at?->toIso8601ZuluString(),
                'km_driven' => $s->km_driven,
                'return_notes' => $s->return_notes,
            ])
            ->values();

        return Inertia::render('Vehicles/Index', [
            'vehicles' => $vehicles,
            'activeSessions' => $activeSessions,
            'recentSessions' => $recentSessions,
            'filters' => $request->only(['search', 'ownership', 'active', 'compliance', 'per_page']),
            'employees' => Employee::query()->orderBy('full_name')->get(['id', 'full_name']),
            'ownerships' => array_map(fn (VehicleOwnership $o): string => $o->value, VehicleOwnership::cases()),
            'fuelTypes' => array_map(fn (FuelType $f): string => $f->value, FuelType::cases()),
            'vehicleTypes' => array_map(fn (VehicleType $t): string => $t->value, VehicleType::cases()),
            'can' => [
                // Permission-only: shown to anyone who may create. The Vue gate
                // routes a company-less Super Admin to the picker; store() sets
                // company_id from the resolved context server-side.
                'create' => Gate::allows('vehicles.create'),
                'edit' => Gate::allows('vehicles.edit'),
                'delete' => Gate::allows('vehicles.delete'),
            ],
        ]);
    }

    public function show(Vehicle $vehicle): Response
    {
        Gate::authorize('vehicles.view');

        $vehicle->load([
            'assignedEmployee:id,full_name',
            'history.employee:id,full_name',
            'maintenanceHistory.creator:id,name',
            'mileageHistory.updatedBy:id,name',
            'dailyAssignments.employee:id,full_name',
            'dailyAssignments.creator:id,name',
            'fines.employee:id,full_name',
            'fuelRecords.employee:id,full_name',
            'sessions.employee:id,full_name',
        ]);

        return Inertia::render('Vehicles/Show', [
            'vehicle' => array_merge($this->row($vehicle), [
                'color' => $vehicle->color,
                'vin_number' => $vehicle->vin_number,
                'insurance_policy_number' => $vehicle->insurance_policy_number,
                'purchase_date' => $vehicle->purchase_date?->toDateString(),
                'last_oil_change_mileage' => $vehicle->last_oil_change_mileage,
                'last_oil_change_date' => $vehicle->last_oil_change_date?->toDateString(),
                'oil_change_interval_km' => $vehicle->oil_change_interval_km,
                'oil_change_due_at' => $vehicle->oilChangeDueAt(),
                'next_service_date' => $vehicle->next_service_date?->toDateString(),
                'last_tyre_change_date' => $vehicle->last_tyre_change_date?->toDateString(),
                'last_tyre_change_mileage' => $vehicle->last_tyre_change_mileage,
                'maintenance_cost_total' => (float) $vehicle->maintenance_cost_total,
                'maintenance_notes' => $vehicle->maintenance_notes,
                'notes' => $vehicle->notes,
            ]),
            'expiries' => $this->compliance->all($vehicle),
            'history' => $vehicle->history->map(fn ($h): array => [
                'id' => $h->id,
                'employee' => $h->employee?->full_name,
                'assigned_from' => $h->assigned_from->toDateTimeString(),
                'assigned_to' => $h->assigned_to?->toDateTimeString(),
                'notes' => $h->notes,
            ])->values(),
            'maintenance' => $vehicle->maintenanceHistory->map(fn ($m): array => [
                'id' => $m->id,
                'maintenance_type' => $m->maintenance_type,
                'maintenance_date' => $m->maintenance_date->toDateString(),
                'vehicle_km' => $m->vehicle_km,
                'description' => $m->description,
                'vendor_name' => $m->vendor_name,
                'tyre_position' => $m->tyre_position,
                'cost' => $m->cost !== null ? (float) $m->cost : null,
                'created_by' => $m->creator?->name,
            ])->values(),
            'mileage' => $vehicle->mileageHistory->map(fn ($m): array => [
                'id' => $m->id,
                'mileage_value' => $m->mileage_value,
                'recorded_at' => $m->recorded_at->toDateString(),
                'updated_by' => $m->updatedBy?->name,
            ])->values(),
            'daily_assignments' => $vehicle->dailyAssignments->map(fn ($d): array => [
                'id' => $d->id,
                'assigned_date' => $d->assigned_date->toDateString(),
                'employee_id' => $d->employee_id,
                'employee' => $d->employee?->full_name,
                'notes' => $d->notes,
                'created_by' => $d->creator?->name,
            ])->values(),
            'fines' => $vehicle->fines->map(fn ($f): array => [
                'id' => $f->id,
                'fine_date' => $f->fine_date->toDateString(),
                'amount' => (float) $f->amount,
                'description' => $f->description,
                'authority' => $f->authority,
                'employee' => $f->employee?->full_name,
                'employee_id' => $f->employee_id,
                'charged_to' => $f->charged_to,
                'paid' => $f->paid,
                'paid_at' => $f->paid_at?->toDateString(),
                'has_expense' => $f->expense_id !== null,
            ])->values(),
            'fuel_records' => $vehicle->fuelRecords->map(fn ($r): array => [
                'id' => $r->id,
                'fuel_date' => $r->fuel_date->toDateString(),
                'litres' => (float) $r->litres,
                'cost_per_litre' => (float) $r->cost_per_litre,
                'total_cost' => (float) $r->total_cost,
                'mileage_at_fill' => $r->mileage_at_fill,
                'payment_method' => $r->payment_method,
                'employee' => $r->employee?->full_name,
                'notes' => $r->notes,
            ])->values(),
            'sessions' => $vehicle->sessions->map(fn ($s): array => [
                'id' => $s->id,
                'employee' => $s->employee?->full_name,
                'taken_at' => $s->taken_at->toDateTimeString(),
                'returned_at' => $s->returned_at?->toDateTimeString(),
                'starting_mileage' => $s->starting_mileage,
                'ending_mileage' => $s->ending_mileage,
                'km_driven' => $s->km_driven,
                'return_notes' => $s->return_notes,
                'open' => $s->isOpen(),
            ])->values(),
            'employees' => Employee::query()->orderBy('full_name')->get(['id', 'full_name']),
            'can' => [
                'edit' => Gate::allows('vehicles.edit'),
                'delete' => Gate::allows('vehicles.delete'),
            ],
        ]);
    }

    public function store(StoreVehicleRequest $request): RedirectResponse
    {
        $this->vehicles->create($request->validated(), $this->contextCompanyId());

        return back()->with('success', __('ui.vehicles.saved'));
    }

    public function update(StoreVehicleRequest $request, Vehicle $vehicle): RedirectResponse
    {
        $this->vehicles->update($vehicle, $request->validated());

        return back()->with('success', __('ui.vehicles.saved'));
    }

    public function destroy(Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize('vehicles.delete');

        $vehicle->delete();

        return redirect()->route('vehicles.index')->with('success', __('ui.vehicles.deleted'));
    }

    public function assign(Request $request, Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize('vehicles.edit');

        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $employeeId = $validated['employee_id'] ?? null;

        // Scope check: a foreign employee does not exist here (Rule 1).
        if ($employeeId !== null && ! Employee::query()->whereKey($employeeId)->exists()) {
            return back()->withErrors(['employee_id' => __('ui.vehicles.employee_not_found')]);
        }

        $this->vehicles->assign($vehicle, $employeeId === null ? null : (int) $employeeId, $validated['notes'] ?? null);

        return back()->with('success', __('ui.vehicles.assigned'));
    }

    public function storeMaintenance(Request $request, Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize('vehicles.edit');

        $validated = $request->validate([
            'maintenance_type' => ['required', 'string', 'max:50'],
            'maintenance_date' => ['required', 'date'],
            'vehicle_km' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'vendor_name' => ['nullable', 'string', 'max:100'],
            'tyre_position' => ['nullable', 'string', 'max:50'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->vehicles->logMaintenance($vehicle, $validated);

        return back()->with('success', __('ui.vehicles.maintenance_logged'));
    }

    public function destroyMaintenance(Vehicle $vehicle, VehicleMaintenanceHistory $maintenance): RedirectResponse
    {
        Gate::authorize('vehicles.edit');

        // The nested record is only reachable through its own vehicle, which
        // the global scope has already proven belongs to this company.
        abort_unless($maintenance->vehicle_id === $vehicle->id, 404);

        $this->vehicles->deleteMaintenance($maintenance);

        return back()->with('success', __('ui.vehicles.maintenance_deleted'));
    }

    public function storeMileage(Request $request, Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize('vehicles.edit');

        $validated = $request->validate([
            'mileage_value' => ['required', 'integer', 'min:0'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $this->vehicles->logMileage($vehicle, (int) $validated['mileage_value'], $validated['recorded_at'] ?? null);

        return back()->with('success', __('ui.vehicles.mileage_logged'));
    }

    public function storeDailyAssignment(StoreDailyAssignmentRequest $request, Vehicle $vehicle): RedirectResponse
    {
        $this->vehicles->logDailyAssignment($vehicle, $request->string('assigned_date')->value(), $request->validated());

        return back()->with('success', __('ui.vehicles.daily_assignment_saved'));
    }

    public function destroyDailyAssignment(Vehicle $vehicle, VehicleDailyAssignment $assignment): RedirectResponse
    {
        Gate::authorize('vehicles.edit');
        abort_unless($assignment->vehicle_id === $vehicle->id, 404);

        $this->vehicles->deleteDailyAssignment($assignment);

        return back()->with('success', __('ui.vehicles.daily_assignment_deleted'));
    }

    public function storeFine(StoreFineRequest $request, Vehicle $vehicle): RedirectResponse
    {
        $this->vehicles->logFine($vehicle, $request->validated());

        return back()->with('success', __('ui.vehicles.fine_saved'));
    }

    public function destroyFine(Vehicle $vehicle, VehicleFine $fine): RedirectResponse
    {
        Gate::authorize('vehicles.edit');
        abort_unless($fine->vehicle_id === $vehicle->id, 404);

        $this->vehicles->deleteFine($fine);

        return back()->with('success', __('ui.vehicles.fine_deleted'));
    }

    public function storeFuel(StoreFuelRequest $request, Vehicle $vehicle): RedirectResponse
    {
        $this->vehicles->logFuel($vehicle, $request->validated());

        return back()->with('success', __('ui.vehicles.fuel_saved'));
    }

    public function destroyFuel(Vehicle $vehicle, VehicleFuelRecord $fuelRecord): RedirectResponse
    {
        Gate::authorize('vehicles.edit');
        abort_unless($fuelRecord->vehicle_id === $vehicle->id, 404);

        $this->vehicles->deleteFuel($fuelRecord);

        return back()->with('success', __('ui.vehicles.fuel_deleted'));
    }

    /**
     * @return Builder<Vehicle>
     */
    private function filteredQuery(Request $request): Builder
    {
        return Vehicle::query()
            ->with('assignedEmployee:id,full_name')
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = '%'.$request->string('search')->value().'%';
                $q->where(fn (Builder $w) => $w->where('plate_number', 'like', $term)
                    ->orWhere('brand', 'like', $term)
                    ->orWhere('model', 'like', $term));
            })
            ->when($request->filled('ownership'), fn (Builder $q) => $q->where('ownership', $request->string('ownership')->value()))
            ->when($request->filled('active'), fn (Builder $q) => $q->where('active', $request->boolean('active')))
            ->orderBy('plate_number');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Vehicle $v): array
    {
        return [
            'id' => $v->id,
            'plate_number' => $v->plate_number,
            'vehicle_type' => $v->vehicle_type?->value,
            'brand' => $v->brand,
            'model' => $v->model,
            'year' => $v->year,
            'ownership' => $v->ownership->value,
            'assigned_employee' => $v->assignedEmployee?->full_name,
            'assigned_employee_id' => $v->assigned_employee_id,
            'fuel_type' => $v->fuel_type?->value,
            'ita_expiry_date' => $v->ita_expiry_date?->toDateString(),
            'insurance_expiry_date' => $v->insurance_expiry_date?->toDateString(),
            'road_tax_expiry_date' => $v->road_tax_expiry_date?->toDateString(),
            'current_mileage' => $v->current_mileage,
            'active' => $v->active,
            // worst of all three expiries (insurance, ITV, road tax)
            'compliance' => $this->compliance->worst($v),
        ];
    }
}
