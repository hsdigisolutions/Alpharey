<?php

namespace App\Services\Vehicles;

use App\Enums\VehicleAssignmentType;
use App\Models\EmployeeVehicleAssignment;
use App\Models\Vehicle;
use App\Models\VehicleHistory;
use App\Models\VehicleMaintenanceHistory;
use App\Models\VehicleMileageHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Screen 21 tabs 2–4. Everything here exists so that the vehicle's summary
 * columns and its history tables can never tell different stories:
 *
 *  - assigning closes the open history row before opening the next one, so a
 *    van is never recorded as held by two people at once
 *  - maintenance_cost_total is a rollup of the maintenance log, recomputed
 *    from it rather than incremented, so a deleted record actually reduces it
 *  - current_mileage tracks the odometer log
 */
class VehicleService
{
    /**
     * Hand the vehicle to an employee, or (null) take it back into the pool.
     */
    public function assign(Vehicle $vehicle, ?int $employeeId, ?string $notes = null): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $employeeId, $notes): Vehicle {
            $this->closeOpenAssignments($vehicle);

            if ($employeeId !== null) {
                VehicleHistory::query()->create([
                    'vehicle_id' => $vehicle->id,
                    'employee_id' => $employeeId,
                    'assigned_from' => now(),
                    'notes' => $notes,
                ]);

                // The employee's side of the same fact (see the model docblock).
                EmployeeVehicleAssignment::query()->create([
                    'employee_id' => $employeeId,
                    'vehicle_id' => $vehicle->id,
                    'type' => VehicleAssignmentType::Company,
                    'effective_from' => now()->toDateString(),
                ]);
            }

            $vehicle->assigned_employee_id = $employeeId;
            $vehicle->save();

            return $vehicle;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function logMaintenance(Vehicle $vehicle, array $data): VehicleMaintenanceHistory
    {
        return DB::transaction(function () use ($vehicle, $data): VehicleMaintenanceHistory {
            $record = new VehicleMaintenanceHistory($data);
            $record->vehicle_id = $vehicle->id;
            $record->created_by = Auth::id();
            $record->save();

            $this->refreshMaintenanceTotal($vehicle);

            return $record;
        });
    }

    public function deleteMaintenance(VehicleMaintenanceHistory $record): void
    {
        DB::transaction(function () use ($record): void {
            $vehicle = $record->vehicle;
            $record->delete();

            $this->refreshMaintenanceTotal($vehicle);
        });
    }

    /**
     * Record an odometer reading.
     *
     * An odometer does not run backwards, so a reading below the current one
     * is refused rather than quietly accepted: it is either a typo or the
     * wrong vehicle, and both are worth stopping at the door. Correcting a
     * genuine mistake means deleting the bad reading first.
     *
     * @throws ValidationException
     */
    public function logMileage(Vehicle $vehicle, int $value, ?string $recordedAt = null): VehicleMileageHistory
    {
        if ($vehicle->current_mileage !== null && $value < $vehicle->current_mileage) {
            throw ValidationException::withMessages([
                'mileage_value' => __('ui.vehicles.mileage_backwards', [
                    'current' => $vehicle->current_mileage,
                ]),
            ]);
        }

        return DB::transaction(function () use ($vehicle, $value, $recordedAt): VehicleMileageHistory {
            $record = new VehicleMileageHistory([
                'vehicle_id' => $vehicle->id,
                'mileage_value' => $value,
                'recorded_at' => $recordedAt ?? now()->toDateString(),
            ]);
            $record->updated_by = Auth::id();
            $record->save();

            $vehicle->current_mileage = $value;
            $vehicle->save();

            return $record;
        });
    }

    /**
     * The owning company is passed in, not resolved here: a Super Admin
     * browsing "all companies" has no active company, and a vehicle always
     * belongs to exactly one fleet. The caller settles that question first
     * (ResolvesCompanyContext) — see CLAUDE.md scaffolding decision 27.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $companyId): Vehicle
    {
        $vehicle = new Vehicle($data);
        $vehicle->company_id = $companyId;
        $vehicle->save();

        // A vehicle created already assigned still needs its history opened,
        // or tab 2 would be empty for a van that plainly has a driver.
        if ($vehicle->assigned_employee_id !== null) {
            VehicleHistory::query()->create([
                'vehicle_id' => $vehicle->id,
                'employee_id' => $vehicle->assigned_employee_id,
                'assigned_from' => now(),
            ]);
        }

        return $vehicle;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Vehicle $vehicle, array $data): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data): Vehicle {
            $newDriver = array_key_exists('assigned_employee_id', $data)
                ? $data['assigned_employee_id']
                : $vehicle->assigned_employee_id;

            unset($data['assigned_employee_id']);
            $vehicle->fill($data);
            $vehicle->save();

            // Route a driver change through assign() so the history is written
            // — editing the field on the form must not bypass tab 2.
            if ($newDriver !== $vehicle->assigned_employee_id) {
                $this->assign($vehicle, $newDriver === null ? null : (int) $newDriver);
            }

            return $vehicle;
        });
    }

    /**
     * The rollup, recomputed from the log — never incremented in place.
     */
    private function refreshMaintenanceTotal(Vehicle $vehicle): void
    {
        $total = (float) VehicleMaintenanceHistory::query()
            ->where('vehicle_id', $vehicle->id)
            ->sum('cost');

        $vehicle->maintenance_cost_total = (string) round($total, 2);
        $vehicle->save();
    }

    /**
     * Close every open assignment row for this vehicle, on both sides.
     */
    private function closeOpenAssignments(Vehicle $vehicle): void
    {
        VehicleHistory::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNull('assigned_to')
            ->update(['assigned_to' => now()]);

        EmployeeVehicleAssignment::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => now()->toDateString()]);
    }
}
