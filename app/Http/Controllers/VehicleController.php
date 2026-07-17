<?php

namespace App\Http\Controllers;

use App\Enums\FuelType;
use App\Enums\VehicleOwnership;
use App\Http\Requests\StoreVehicleRequest;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceHistory;
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

        return Inertia::render('Vehicles/Index', [
            'vehicles' => $vehicles,
            'filters' => $request->only(['search', 'ownership', 'active', 'compliance', 'per_page']),
            'employees' => Employee::query()->orderBy('full_name')->get(['id', 'full_name']),
            'ownerships' => array_map(fn (VehicleOwnership $o): string => $o->value, VehicleOwnership::cases()),
            'fuelTypes' => array_map(fn (FuelType $f): string => $f->value, FuelType::cases()),
            'can' => [
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
            'employees' => Employee::query()->orderBy('full_name')->get(['id', 'full_name']),
            'can' => [
                'edit' => Gate::allows('vehicles.edit'),
                'delete' => Gate::allows('vehicles.delete'),
            ],
        ]);
    }

    public function store(StoreVehicleRequest $request): RedirectResponse
    {
        $this->vehicles->create($request->validated());

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
            'brand' => $v->brand,
            'model' => $v->model,
            'year' => $v->year,
            'ownership' => $v->ownership->value,
            'assigned_employee' => $v->assignedEmployee?->full_name,
            'assigned_employee_id' => $v->assigned_employee_id,
            'fuel_type' => $v->fuel_type?->value,
            'ita_expiry_date' => $v->ita_expiry_date?->toDateString(),
            'insurance_expiry_date' => $v->insurance_expiry_date?->toDateString(),
            'current_mileage' => $v->current_mileage,
            'active' => $v->active,
            // the row indicator — worst of the two expiries
            'compliance' => $this->compliance->worst($v),
        ];
    }
}
