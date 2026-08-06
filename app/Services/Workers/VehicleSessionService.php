<?php

namespace App\Services\Workers;

use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Models\Vehicle;
use App\Models\VehicleSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Worker vehicle sessions: take → (fuel mid-session) → return.
 *
 * Taking a vehicle flips vehicles.is_available = false; returning it flips it
 * back to true. The flag is the fast availability signal for the PWA list (a
 * 30-second poll) — no WebSocket server required on cPanel.
 *
 * Tenancy: the worker is one employee with one company; the vehicle must belong
 * to the same company. company_id never comes from request input.
 */
class VehicleSessionService
{
    /**
     * Start a session. Refuses if the vehicle is not available or the worker
     * already has an open session on any vehicle.
     */
    public function take(Vehicle $vehicle, Employee $employee, int $startingMileage, ?int $fuelLevel): VehicleSession
    {
        if (! $vehicle->is_available) {
            throw ValidationException::withMessages([
                'vehicle_id' => __('ui.worker_vehicles.not_available'),
            ]);
        }

        $open = VehicleSession::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->whereNull('returned_at')
            ->exists();

        if ($open) {
            throw ValidationException::withMessages([
                'vehicle_id' => __('ui.worker_vehicles.already_have_vehicle'),
            ]);
        }

        return DB::transaction(function () use ($vehicle, $employee, $startingMileage, $fuelLevel): VehicleSession {
            $session = new VehicleSession([
                'vehicle_id' => $vehicle->id,
                'employee_id' => $employee->id,
                'taken_at' => now(),
                'starting_mileage' => $startingMileage,
                'starting_fuel_level' => $fuelLevel,
            ]);
            $session->company_id = $employee->company_id;
            $session->save();

            $vehicle->is_available = false;
            $vehicle->save();

            return $session;
        });
    }

    /**
     * Record that the worker added fuel during the session (optional mid-session
     * action — does not close the session).
     */
    public function logFuel(VehicleSession $session, float $litres): VehicleSession
    {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages([
                'session' => __('ui.worker_vehicles.session_closed'),
            ]);
        }

        $session->fuel_added_litres = (string) (((float) ($session->fuel_added_litres ?? 0)) + $litres);
        $session->save();

        return $session;
    }

    /**
     * Return the vehicle. Sets returned_at, computes km_driven, restores
     * is_available = true.
     */
    public function returnVehicle(VehicleSession $session, int $endingMileage, ?int $endingFuelLevel, ?string $notes): VehicleSession
    {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages([
                'session' => __('ui.worker_vehicles.session_closed'),
            ]);
        }

        if ($endingMileage < $session->starting_mileage) {
            throw ValidationException::withMessages([
                'ending_mileage' => __('ui.worker_vehicles.mileage_backwards'),
            ]);
        }

        return DB::transaction(function () use ($session, $endingMileage, $endingFuelLevel, $notes): VehicleSession {
            $session->returned_at = now();
            $session->ending_mileage = $endingMileage;
            $session->km_driven = $endingMileage - $session->starting_mileage;
            $session->ending_fuel_level = $endingFuelLevel;
            $session->return_notes = $notes;
            $session->save();

            // Load without CompanyScope — workers have no CRM session so the
            // scope yields NULL and the relation returns null for them.
            $vehicle = Vehicle::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->findOrFail($session->vehicle_id);
            $vehicle->is_available = true;
            // Update the vehicle's current mileage if the return mileage is higher.
            if ($vehicle->current_mileage === null || $endingMileage > $vehicle->current_mileage) {
                $vehicle->current_mileage = $endingMileage;
            }
            $vehicle->save();

            return $session;
        });
    }
}
