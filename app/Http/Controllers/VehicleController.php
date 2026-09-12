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
use App\Models\Scopes\CompanyScope;
use App\Models\Vehicle;
use App\Models\VehicleDailyAssignment;
use App\Models\VehicleFine;
use App\Models\VehicleFuelRecord;
use App\Models\VehicleMaintenanceHistory;
use App\Models\VehicleSession;
use App\Models\WorkerExpense;
use App\Rules\OwnCompanyEmployee;
use App\Services\Audit\AuditLogger;
use App\Services\Vehicles\VehicleCompliance;
use App\Services\Vehicles\VehicleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

        $recent = VehicleSession::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereNotNull('returned_at')
            ->with(['employee:id,full_name', 'vehicle:id,plate_number,brand,model'])
            ->orderBy('returned_at', 'desc')
            ->limit(30)
            ->get();

        // Prefetch fuel (per employee) + fines (per vehicle) once, so every recent
        // row can open the same luxury detail panel without an N+1.
        $fuelByEmp = WorkerExpense::query()->withoutGlobalScope(CompanyScope::class)
            ->whereIn('employee_id', $recent->pluck('employee_id')->unique()->filter()->all())
            ->where('category', 'fuel')
            ->get(['id', 'employee_id', 'date', 'amount', 'receipt_path', 'description'])
            ->groupBy('employee_id');
        $finesByVeh = VehicleFine::query()->withoutGlobalScope(CompanyScope::class)
            ->whereIn('vehicle_id', $recent->pluck('vehicle_id')->unique()->all())
            ->get(['id', 'vehicle_id', 'fine_date', 'amount', 'description', 'paid'])
            ->groupBy('vehicle_id');

        $recentSessions = $recent->map(fn (VehicleSession $s): array => $this->enrichSession(
            $s,
            trim("{$s->vehicle?->plate_number} · {$s->vehicle?->brand} {$s->vehicle?->model}"),
            $fuelByEmp[$s->employee_id] ?? collect(),
            $finesByVeh[$s->vehicle_id] ?? collect(),
        ))->values();

        return Inertia::render('Vehicles/Index', [
            'vehicles' => $vehicles,
            'activeSessions' => $activeSessions,
            'recentSessions' => $recentSessions,
            'filters' => (object) $request->only(['search', 'ownership', 'active', 'compliance', 'per_page']),
            'employees' => Employee::query()->active()->orderBy('full_name')->get(['id', 'full_name']),
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

        // Worker fuel expenses (category 'fuel') for everyone who has driven this
        // vehicle — ONE query, matched per session in PHP for the detail card.
        $fuelByEmployee = WorkerExpense::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $vehicle->company_id)
            ->whereIn('employee_id', $vehicle->sessions->pluck('employee_id')->unique()->filter()->all())
            ->where('category', 'fuel')
            ->orderByDesc('date')
            ->get(['id', 'employee_id', 'date', 'amount', 'receipt_path', 'description'])
            ->groupBy('employee_id');

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
                'deduct_from_salary' => $f->deduct_from_salary,
                'deduction_month' => $f->deduction_month,
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
            'sessions' => $vehicle->sessions->map(fn (VehicleSession $s): array => $this->enrichSession(
                $s,
                trim("{$vehicle->plate_number} · {$vehicle->brand} {$vehicle->model}"),
                $fuelByEmployee[$s->employee_id] ?? collect(),
                $vehicle->fines,
            ))->values(),
            'employees' => Employee::query()->active()->orderBy('full_name')->get(['id', 'full_name']),
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

    /**
     * The full luxury-card payload for one worker session: media download URLs,
     * computed duration, worker fuel (€ + receipt) added in the window, and any
     * fines recorded on the vehicle in the window. Reused by the vehicle detail
     * sessions tab AND the Recent-activity list on the fleet index.
     *
     * @param  Collection<int, WorkerExpense>  $fuelForEmployee
     * @param  Collection<int, VehicleFine>  $finesForVehicle
     * @return array<string, mixed>
     */
    private function enrichSession(VehicleSession $s, string $vehicleName, Collection $fuelForEmployee, Collection $finesForVehicle): array
    {
        $from = $s->taken_at->toDateString();
        $to = ($s->returned_at ?? now())->toDateString();

        $fuel = $fuelForEmployee
            ->filter(fn ($e) => $e->date->toDateString() >= $from && $e->date->toDateString() <= $to)
            ->map(fn ($e): array => [
                'amount' => (float) $e->amount,
                'description' => $e->description,
                'date' => $e->date->toDateString(),
                // Item 5 — the receipt is downloaded through the mirror Expense
                // (the Worker Expenses tab is gone); every submission now has one.
                'receipt_url' => $e->auto_expense_id !== null ? route('expenses.receipt', $e->auto_expense_id) : null,
            ])->values()->all();

        $fines = $finesForVehicle
            ->filter(fn ($f) => $f->fine_date->toDateString() >= $from && $f->fine_date->toDateString() <= $to)
            ->map(fn ($f): array => [
                'amount' => (float) $f->amount,
                'description' => $f->description,
                'paid' => (bool) $f->paid,
                'fine_date' => $f->fine_date->toDateString(),
            ])->values()->all();

        $mediaUrl = fn (string $kind, string $which, ?string $path): ?string => $path !== null
            ? route("vehicles.sessions.{$kind}", ['vehicle' => $s->vehicle_id, 'session' => $s->id, 'which' => $which])
            : null;

        return [
            'id' => $s->id,
            'employee' => $s->employee?->full_name,
            'employee_id' => $s->employee_id,
            'vehicle_id' => $s->vehicle_id,
            'vehicle_name' => $vehicleName,
            'taken_at' => $s->taken_at->toDateTimeString(),
            'returned_at' => $s->returned_at?->toDateTimeString(),
            'duration_minutes' => $s->returned_at !== null ? (int) $s->taken_at->diffInMinutes($s->returned_at) : null,
            'starting_mileage' => $s->starting_mileage,
            'ending_mileage' => $s->ending_mileage,
            'km_driven' => $s->km_driven,
            'return_notes' => $s->return_notes,
            'open' => $s->isOpen(),
            'take_photo_url' => $mediaUrl('photo', 'take', $s->take_photo_path),
            'return_photo_url' => $mediaUrl('photo', 'return', $s->return_photo_path),
            'take_voice_url' => $mediaUrl('voice', 'take', $s->take_voice_note_path),
            'return_voice_url' => $mediaUrl('voice', 'return', $s->return_voice_note_path),
            'take_voice_duration' => $s->take_voice_duration,
            'return_voice_duration' => $s->return_voice_duration,
            'fuel' => $fuel,
            'fines' => $fines,
        ];
    }

    /** A worker session's condition photo (which = take|return). */
    public function sessionPhoto(Vehicle $vehicle, VehicleSession $session, string $which): BinaryFileResponse
    {
        return $this->streamSessionMedia($vehicle, $session, 'photo', $which);
    }

    /** A worker session's voice note (which = take|return). */
    public function sessionVoice(Vehicle $vehicle, VehicleSession $session, string $which): BinaryFileResponse
    {
        return $this->streamSessionMedia($vehicle, $session, 'voice', $which);
    }

    /**
     * Stream a session media file, gated + audited. Tenancy: the vehicle is
     * company-scoped by its route binding, and the session must belong to it —
     * VehicleSession's own binding drops the scope (for workers), so this check
     * is what confines an admin to their own company's sessions.
     */
    private function streamSessionMedia(Vehicle $vehicle, VehicleSession $session, string $kind, string $which): BinaryFileResponse
    {
        Gate::authorize('vehicles.view');
        abort_unless($session->vehicle_id === $vehicle->id, 404);
        abort_unless(in_array($which, ['take', 'return'], true), 404);

        $column = match ("{$kind}.{$which}") {
            'photo.take' => 'take_photo_path',
            'photo.return' => 'return_photo_path',
            'voice.take' => 'take_voice_note_path',
            'voice.return' => 'return_voice_note_path',
            default => null,
        };
        $path = $column !== null ? $session->{$column} : null;
        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        app(AuditLogger::class)->log('viewed', $session, null, null, "Vehicle session {$kind} ({$which})", 'vehicles');

        return response()->file(Storage::disk('local')->path($path));
    }

    /**
     * Explicitly deduct (or stop deducting) a fine from a worker's salary. A
     * fine NEVER comes off pay on its own — the admin decides here, per fine,
     * naming the employee and the payroll month it lands in.
     */
    public function deductFine(Request $request, Vehicle $vehicle, VehicleFine $fine): RedirectResponse
    {
        Gate::authorize('vehicles.edit');
        abort_unless($fine->vehicle_id === $vehicle->id, 404);

        $validated = $request->validate([
            'deduct_from_salary' => ['required', 'boolean'],
            'employee_id' => ['nullable', 'integer', new OwnCompanyEmployee],
            'deduction_month' => ['nullable', 'required_if:deduct_from_salary,true', 'date_format:Y-m'],
        ]);

        $fine->deduct_from_salary = (bool) $validated['deduct_from_salary'];

        if ($fine->deduct_from_salary) {
            // A salary deduction must name the worker whose pay it comes off.
            $employeeId = $validated['employee_id'] ?? $fine->employee_id;
            abort_if($employeeId === null, 422);
            $fine->employee_id = (int) $employeeId;
            $fine->deduction_month = $validated['deduction_month'];
        } else {
            $fine->deduction_month = null;
        }

        $fine->save();

        return back()->with('success', __('ui.vehicles.fine_saved'));
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
