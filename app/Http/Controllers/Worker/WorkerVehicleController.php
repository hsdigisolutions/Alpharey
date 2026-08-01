<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Models\Vehicle;
use App\Models\VehicleSession;
use App\Services\Workers\VehicleSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Feature 4 — Vehicle check-in/out for workers.
 *
 * The vehicle list refreshes every 30 s on the client (Inertia router.reload).
 * vehicle_id + session ownership always validated server-side; company_id comes
 * from the worker's employee record, never from request input.
 */
class WorkerVehicleController extends Controller
{
    public function __construct(private readonly VehicleSessionService $service) {}

    public function index(Request $request): Response
    {
        $employee = $this->resolveEmployee($request);

        abort_unless($employee->can_use_vehicles, 403);

        $vehicles = Vehicle::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $employee->company_id)
            ->where('active', true)
            ->with(['openSession.employee:id,full_name'])
            ->orderBy('plate_number')
            ->get()
            ->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'plate_number' => $v->plate_number,
                'brand' => $v->brand,
                'model' => $v->model,
                'fuel_type' => $v->fuel_type?->value,
                'is_available' => $v->is_available,
                'taken_by' => $v->openSession->first()?->employee?->full_name,
            ]);

        $mySession = VehicleSession::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->whereNull('returned_at')
            ->with('vehicle:id,plate_number,brand,model')
            ->first();

        return Inertia::render('Worker/Vehicles', [
            'vehicles' => $vehicles,
            'my_session' => $mySession ? [
                'id' => $mySession->id,
                'taken_at' => $mySession->taken_at,
                'starting_mileage' => $mySession->starting_mileage,
                'starting_fuel_level' => $mySession->starting_fuel_level,
                'fuel_added_litres' => $mySession->fuel_added_litres,
                'vehicle' => [
                    'id' => $mySession->vehicle->id,
                    'plate_number' => $mySession->vehicle->plate_number,
                    'brand' => $mySession->vehicle->brand,
                    'model' => $mySession->vehicle->model,
                ],
            ] : null,
            'worker' => [
                'name' => $employee->full_name,
                'code' => $employee->employee_code,
                'company' => $employee->company?->name,
            ],
        ]);
    }

    public function take(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        abort_unless($employee->can_use_vehicles, 403);

        // Scope-drop needed: the vehicle belongs to the employee's company but
        // the CompanyScope requires a session selection workers don't have.
        $vehicle = Vehicle::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $employee->company_id)
            ->findOrFail($vehicle->id);

        $validated = $request->validate([
            'starting_mileage' => ['required', 'integer', 'min:0'],
            'starting_fuel_level' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $this->service->take(
            $vehicle,
            $employee,
            (int) $validated['starting_mileage'],
            isset($validated['starting_fuel_level']) ? (int) $validated['starting_fuel_level'] : null,
        );

        return back()->with('success', __('ui.worker_vehicles.taken'));
    }

    public function logFuel(Request $request, VehicleSession $session): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        abort_unless((int) $session->employee_id === $employee->id, 403);

        $validated = $request->validate([
            'litres' => ['required', 'numeric', 'min:0.1', 'max:300'],
        ]);

        $this->service->logFuel($session, (float) $validated['litres']);

        return back()->with('success', __('ui.worker_vehicles.fuel_logged'));
    }

    public function returnVehicle(Request $request, VehicleSession $session): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        abort_unless((int) $session->employee_id === $employee->id, 403);

        $validated = $request->validate([
            'ending_mileage' => ['required', 'integer', 'min:0'],
            'ending_fuel_level' => ['nullable', 'integer', 'min:0', 'max:100'],
            'return_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->returnVehicle(
            $session,
            (int) $validated['ending_mileage'],
            isset($validated['ending_fuel_level']) ? (int) $validated['ending_fuel_level'] : null,
            $validated['return_notes'] ?? null,
        );

        return back()->with('success', __('ui.worker_vehicles.returned'));
    }

    private function resolveEmployee(Request $request): Employee
    {
        return Employee::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with('company:id,name')
            ->where('user_id', $request->user()?->id)
            ->firstOrFail();
    }
}
