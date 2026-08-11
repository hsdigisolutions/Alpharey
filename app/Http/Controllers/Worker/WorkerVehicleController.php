<?php

namespace App\Http\Controllers\Worker;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Models\Vehicle;
use App\Models\VehicleFine;
use App\Models\VehicleSession;
use App\Models\WorkerExpense;
use App\Services\Notifications\NotificationDispatcher;
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
            ->with(['openSession' => fn ($q) => $q
                ->withoutGlobalScope(CompanyScope::class)
                ->with(['employee' => fn ($eq) => $eq
                    ->withoutGlobalScope(CompanyScope::class)
                    ->select('id', 'full_name'),
                ]),
            ])
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
            ->with(['vehicle' => fn ($q) => $q
                ->withoutGlobalScope(CompanyScope::class)
                ->select('id', 'plate_number', 'brand', 'model'),
            ])
            ->first();

        $fuelExpenseCount = $mySession
            ? WorkerExpense::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('employee_id', $employee->id)
                ->where('category', 'fuel')
                ->where('date', '>=', $mySession->taken_at->toDateString())
                ->count()
            : 0;

        $fines = VehicleFine::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->where('company_id', $employee->company_id)
            ->orderByDesc('fine_date')
            ->get()
            ->map(fn (VehicleFine $f) => [
                'id' => $f->id,
                'fine_date' => $f->fine_date->toDateString(),
                'amount' => $f->amount,
                'description' => $f->description,
                'authority' => $f->authority,
                'paid' => (bool) $f->paid,
            ]);

        return Inertia::render('Worker/Vehicles', [
            'vehicles' => $vehicles,
            'fines' => $fines,
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

    public function take(Request $request, int $vehicle): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        abort_unless($employee->can_use_vehicles, 403);

        // Route model binding is bypassed (raw int) because CompanyScope requires
        // a CRM session that workers don't have. Ownership verified here instead.
        $vehicle = Vehicle::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $employee->company_id)
            ->findOrFail($vehicle);

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
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999.99'],
            'description' => ['nullable', 'string', 'max:500'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
        ]);

        $expense = new WorkerExpense([
            'employee_id' => $employee->id,
            'date' => now()->toDateString(),
            'amount' => $validated['amount'],
            'category' => 'fuel',
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

        // Fuel logged as a worker expense is a receipt awaiting admin review.
        app(NotificationDispatcher::class)->dispatch(
            NotificationType::ExpensePending,
            (int) $employee->company_id,
            [
                'title_es' => "Nuevo gasto de combustible de {$employee->full_name}",
                'title_en' => "New fuel expense from {$employee->full_name}",
                'entity' => $employee->full_name, 'url' => '/worker-expenses',
            ],
        );

        return back()->with('success', __('ui.worker_vehicles.fuel_logged'));
    }

    public function returnVehicle(Request $request, VehicleSession $session): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        abort_unless((int) $session->employee_id === $employee->id, 403);

        $validated = $request->validate([
            'ending_mileage' => ['required', 'integer', 'min:0'],
            'return_notes' => ['nullable', 'string', 'max:500'],
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
