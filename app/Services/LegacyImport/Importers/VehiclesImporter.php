<?php

namespace App\Services\LegacyImport\Importers;

use App\Enums\FuelType;
use App\Enums\VehicleAssignmentType;
use App\Enums\VehicleOwnership;
use App\Models\Company;
use App\Models\EmployeeVehicleAssignment;
use App\Models\Vehicle;
use App\Models\VehicleHistory;
use App\Models\VehicleMaintenanceHistory;
use App\Models\VehicleMileageHistory;
use App\Services\LegacyImport\AbstractImporter;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy vehicles + their three history tables (DATA_MIGRATION.md §3.8).
 *
 * Two legacy quirks handled explicitly:
 *  - `assigned_emp_id` is the column; the new schema calls it
 *    `assigned_employee_id`
 *  - legacy spells it `tire`, the new schema (and the spec) `tyre`
 *
 * `maintenance_cost` migrates VERBATIM into maintenance_cost_total rather than
 * being recomputed from the imported maintenance rows: the legacy figure is
 * what the client has been reading, and a legacy log may well be incomplete.
 * The rollup takes over from the next maintenance record onward.
 */
class VehiclesImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'vehicles';
    }

    protected function import(): void
    {
        $defaultCompanyId = Company::query()->orderBy('id')->value('id');

        if ($defaultCompanyId === null) {
            $this->exception('vehicles', null, 'No companies exist — run the seeder first.');

            return;
        }

        $this->legacy('vehicles')->orderBy('id')->chunk(500, function ($rows) use ($defaultCompanyId): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                $vehicle = new Vehicle([
                    'plate_number' => (string) $row->plate_number,
                    'brand' => $row->brand ?? null,
                    'model' => $row->model ?? null,
                    'year' => $row->year ?? null,
                    'ownership' => $this->normalizeOwnership($row->ownership ?? null)->value,
                    'assigned_employee_id' => ($row->assigned_emp_id ?? null) !== null
                        ? $this->newIdFor($row->assigned_emp_id, 'employees')
                        : null,
                    'active' => (bool) ($row->active ?? true),
                    'fuel_type' => $this->normalizeFuel($row->fuel_type ?? null)?->value,
                    'color' => $row->color ?? null,
                    'vin_number' => $row->vin_number ?? null,
                    'insurance_policy_number' => $row->insurance_policy_number ?? null,
                    'insurance_expiry_date' => $row->insurance_expiry_date ?? null,
                    'ita_expiry_date' => $row->ita_expiry_date ?? null,
                    'purchase_date' => $row->purchase_date ?? null,
                    'current_mileage' => $row->current_mileage ?? null,
                    'last_oil_change_mileage' => $row->last_oil_change_mileage ?? null,
                    'last_oil_change_date' => $row->last_oil_change_date ?? null,
                    'oil_change_interval_km' => $row->oil_change_interval_km ?? null,
                    'next_service_date' => $row->next_service_date ?? null,
                    // legacy 'tire' -> 'tyre'
                    'last_tyre_change_date' => $row->last_tire_change_date ?? null,
                    'last_tyre_change_mileage' => $row->last_tire_change_mileage ?? null,
                    'maintenance_notes' => $row->maintenance_notes ?? null,
                    'notes' => $row->notes ?? null,
                ]);
                $vehicle->company_id = $defaultCompanyId;
                // verbatim, not recomputed from the imported log
                $vehicle->maintenance_cost_total = (string) ($row->maintenance_cost ?? 0);
                $vehicle->save();

                $this->recordMapping($row->id, $vehicle->id);
                $this->imported++;

                $this->importHistory($vehicle, $row->id);
                $this->importMaintenance($vehicle, $row->id);
                $this->importMileage($vehicle, $row->id);
            }
        });
    }

    private function importHistory(Vehicle $vehicle, string|int $legacyId): void
    {
        if (! Schema::connection('legacy')->hasTable('vehicle_history')) {
            return;
        }

        foreach ($this->legacy('vehicle_history')->where('vehicle_id', $legacyId)->orderBy('id')->get() as $row) {
            $history = new VehicleHistory([
                'vehicle_id' => $vehicle->id,
                'employee_id' => ($row->employee_id ?? null) !== null
                    ? $this->newIdFor($row->employee_id, 'employees')
                    : null,
                'assigned_from' => $row->assigned_from,
                'assigned_to' => $row->assigned_to ?? null,
                'notes' => $row->notes ?? null,
            ]);
            $history->save();
        }

        // The employee-side mirror, if the legacy dump has it.
        if (! Schema::connection('legacy')->hasTable('employee_vehicle_assignments')) {
            return;
        }

        foreach ($this->legacy('employee_vehicle_assignments')->where('vehicle_id', $legacyId)->orderBy('id')->get() as $row) {
            $employeeId = ($row->employee_id ?? null) !== null
                ? $this->newIdFor($row->employee_id, 'employees')
                : null;

            if ($employeeId === null) {
                continue;
            }

            EmployeeVehicleAssignment::query()->create([
                'employee_id' => $employeeId,
                'vehicle_id' => $vehicle->id,
                'type' => (VehicleAssignmentType::tryFrom((string) ($row->type ?? '')) ?? VehicleAssignmentType::Company)->value,
                'effective_from' => $row->effective_from,
                'effective_to' => $row->effective_to ?? null,
            ]);
        }
    }

    private function importMaintenance(Vehicle $vehicle, string|int $legacyId): void
    {
        if (! Schema::connection('legacy')->hasTable('vehicle_maintenance_histories')) {
            return;
        }

        foreach ($this->legacy('vehicle_maintenance_histories')->where('vehicle_id', $legacyId)->orderBy('id')->get() as $row) {
            $record = new VehicleMaintenanceHistory([
                'vehicle_id' => $vehicle->id,
                'maintenance_type' => (string) ($row->maintenance_type ?? 'other'),
                'maintenance_date' => $row->maintenance_date,
                'vehicle_km' => $row->vehicle_km ?? null,
                'description' => $row->description ?? null,
                'tyre_position' => $row->tyre_position ?? null,
                // legacy has no per-record cost: only the vehicle-level total,
                // which is why that total is carried over verbatim above.
                'cost' => null,
            ]);
            $creatorId = ($row->created_by ?? null) !== null
                ? $this->newIdFor($row->created_by, 'users')
                : null;
            $record->created_by = $creatorId !== null ? (int) $creatorId : null;
            $record->save();
        }
    }

    private function importMileage(Vehicle $vehicle, string|int $legacyId): void
    {
        if (! Schema::connection('legacy')->hasTable('vehicle_mileage_histories')) {
            return;
        }

        foreach ($this->legacy('vehicle_mileage_histories')->where('vehicle_id', $legacyId)->orderBy('id')->get() as $row) {
            $record = new VehicleMileageHistory([
                'vehicle_id' => $vehicle->id,
                'mileage_value' => (int) ($row->mileage_value ?? 0),
                'recorded_at' => $row->recorded_at,
            ]);
            $updaterId = ($row->updated_by ?? null) !== null
                ? $this->newIdFor($row->updated_by, 'users')
                : null;
            $record->updated_by = $updaterId !== null ? (int) $updaterId : null;
            $record->save();
        }
    }

    private function normalizeOwnership(?string $legacy): VehicleOwnership
    {
        return VehicleOwnership::tryFrom((string) $legacy) ?? VehicleOwnership::Company;
    }

    private function normalizeFuel(?string $legacy): ?FuelType
    {
        return FuelType::tryFrom((string) $legacy);
    }
}
