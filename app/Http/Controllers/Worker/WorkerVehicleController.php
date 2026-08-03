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
                'current_mileage' => $v->current_mileage,
                'is_available' => $v->is_available,
                'taken_by' => $v->openSession->first()?->employee?->full_name,
            ]);

        $mySession = VehicleSession::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->whereNull('returned_at')
            ->with('vehicle:id,plate_number,brand,model')
            ->first();

        $fuelExpenseCount = $mySession
            ? \App\Models\WorkerExpense::query()
                ->withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
                ->where('employee_id', $employee->id)
                ->where('category', 'fuel')
                ->where('date', '>=', $mySession->taken_at->toDateString())
                ->count()
            : 0;

        return Inertia::render('Worker/Vehicles', [
            'vehicles' => $vehicles,
            'my_session' => $mySession ? [
                'id' => $mySession->id,
                'taken_at' => $mySession->taken_at,
                'starting_mileage' => $mySession->starting_mileage,
                'fuel_expenses_count' => $fuelExpenseCount,
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

        $minMileage = (int) ($vehicle->current_mileage ?? 0);
        $validated = $request->validate([
            'starting_mileage' => ['required', 'integer', "min:{$minMileage}"],
        ]);

        $this->service->take(
            $vehicle,
            $employee,
            (int) $validated['starting_mileage'],
            null,
        );

        return back()->with('success', __('ui.worker_vehicles.taken'));
    }

    public function logFuel(Request $request, VehicleSession $session): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        abort_unless((int) $session->employee_id === $employee->id, 403);

        $validated = $request->validate([
            'amount'      => ['required', 'numeric', 'min:0.01', 'max:9999.99'],
            'description' => ['nullable', 'string', 'max:500'],
            'receipt'     => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
        ]);

        $expense = new \App\Models\WorkerExpense([
            'employee_id' => $employee->id,
            'date'        => now()->toDateString(),
            'amount'      => $validated['amount'],
            'category'    => 'fuel',
            'description' => $validated['description'] ?? '',
        ]);
        $expense->company_id = $employee->company_id;

        if ($request->hasFile('receipt')) {
            $path = $request->file('receipt')->store(
                "worker-expense-receipts/{$employee->company_id}/{$employee->id}",
                'local',
            );
            $expense->receipt_path = $path === false ? null : $path;
        }

        $expense->save();

        return back()->with('success', __('ui.worker_vehicles.fuel_logged'));
    }

    public function returnVehicle(Request $request, VehicleSession $session): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        abort_unless((int) $session->employee_id === $employee->id, 403);

        $validated = $request->validate([
            'ending_mileage' => ['required', 'integer', 'min:0'],
            'return_notes'   => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->returnVehicle(
            $session,
            (int) $validated['ending_mileage'],
            null,
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
