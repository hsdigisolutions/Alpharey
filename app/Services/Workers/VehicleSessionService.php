<?php

namespace App\Services\Workers;

use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Models\Vehicle;
use App\Models\VehicleMileageHistory;
use App\Models\VehicleSession;
use Illuminate\Http\UploadedFile;
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
     *
     * A condition photo + optional voice note (the worker's evidence of the
     * vehicle state at pickup) are stored on the private disk; their paths are
     * server-set, never mass-assignable.
     */
    public function take(
        Vehicle $vehicle,
        Employee $employee,
        int $startingMileage,
        ?int $fuelLevel,
        ?UploadedFile $photo = null,
        ?UploadedFile $voice = null,
        ?int $voiceDuration = null,
    ): VehicleSession {
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

        // Files are stored BEFORE the transaction (a file write is not
        // transactional). A stray file on a failed row is harmless.
        $photoPath = $this->storeMedia($photo, $employee);
        $voicePath = $this->storeMedia($voice, $employee);

        return DB::transaction(function () use ($vehicle, $employee, $startingMileage, $fuelLevel, $photoPath, $voicePath, $voiceDuration): VehicleSession {
            $session = new VehicleSession([
                'vehicle_id' => $vehicle->id,
                'employee_id' => $employee->id,
                'taken_at' => now(),
                'starting_mileage' => $startingMileage,
                'starting_fuel_level' => $fuelLevel,
            ]);
            $session->company_id = $employee->company_id;
            // NOT fillable — set directly.
            $session->take_photo_path = $photoPath;
            $session->take_voice_note_path = $voicePath;
            $session->take_voice_duration = $voicePath !== null ? $voiceDuration : null;
            $session->save();

            $vehicle->is_available = false;
            $vehicle->save();

            return $session;
        });
    }

    /**
     * Store a session media file (photo / voice) on the private disk, per
     * company + employee, randomized name. Returns null when no file is given.
     */
    private function storeMedia(?UploadedFile $file, Employee $employee): ?string
    {
        if ($file === null) {
            return null;
        }

        $path = $file->store("vehicle-sessions/{$employee->company_id}/{$employee->id}", 'local');

        return $path === false ? null : $path;
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
     * is_available = true, stores the return condition photo (+ optional voice),
     * and AUTO-CREATES an odometer row (source='worker_session', linked back to
     * the session) so the admin Mileage log reflects worker-driven distance.
     */
    public function returnVehicle(
        VehicleSession $session,
        int $endingMileage,
        ?int $endingFuelLevel,
        ?string $notes,
        ?UploadedFile $photo = null,
        ?UploadedFile $voice = null,
        ?int $voiceDuration = null,
    ): VehicleSession {
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

        $employee = Employee::query()->withoutGlobalScope(CompanyScope::class)->findOrFail($session->employee_id);
        $photoPath = $this->storeMedia($photo, $employee);
        $voicePath = $this->storeMedia($voice, $employee);

        return DB::transaction(function () use ($session, $endingMileage, $endingFuelLevel, $notes, $photoPath, $voicePath, $voiceDuration): VehicleSession {
            $session->returned_at = now();
            $session->ending_mileage = $endingMileage;
            $session->km_driven = $endingMileage - $session->starting_mileage;
            $session->ending_fuel_level = $endingFuelLevel;
            $session->return_notes = $notes;
            // NOT fillable — set directly.
            $session->return_photo_path = $photoPath;
            $session->return_voice_note_path = $voicePath;
            $session->return_voice_duration = $voicePath !== null ? $voiceDuration : null;
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

            // Auto-create the odometer reading from this return so the admin
            // Mileage tab is no longer blank for worker-driven vehicles.
            VehicleMileageHistory::create([
                'vehicle_id' => $vehicle->id,
                'mileage_value' => $endingMileage,
                'recorded_at' => $session->returned_at,
                'source' => 'worker_session',
                'session_id' => $session->id,
                'km_driven' => $session->km_driven,
            ]);

            return $session;
        });
    }
}
