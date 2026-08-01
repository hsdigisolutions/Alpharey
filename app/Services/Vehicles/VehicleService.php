<?php

namespace App\Services\Vehicles;

use App\Enums\ExpenseType;
use App\Enums\PaymentStatus;
use App\Enums\VehicleAssignmentType;
use App\Models\Employee;
use App\Models\EmployeeVehicleAssignment;
use App\Models\Expense;
use App\Models\Scopes\CompanyScope;
use App\Models\Vehicle;
use App\Models\VehicleDailyAssignment;
use App\Models\VehicleFine;
use App\Models\VehicleFuelRecord;
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
     * Record who drove a vehicle on a specific calendar date. One row per
     * (vehicle, date) — calling this again for the same date replaces the entry
     * rather than stacking rows (updateOrCreate on the unique constraint).
     *
     * @param  array{employee_id?: int|null, notes?: string|null}  $data
     */
    public function logDailyAssignment(Vehicle $vehicle, string $date, array $data): VehicleDailyAssignment
    {
        $record = VehicleDailyAssignment::withoutGlobalScope(CompanyScope::class)
            ->updateOrCreate(
                ['vehicle_id' => $vehicle->id, 'assigned_date' => $date],
                array_filter([
                    'company_id' => $vehicle->company_id,
                    'employee_id' => $data['employee_id'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                ], fn ($v): bool => $v !== null),
            );

        return $record;
    }

    public function deleteDailyAssignment(VehicleDailyAssignment $assignment): void
    {
        $assignment->delete();
    }

    /**
     * Record a traffic fine. The driver is auto-resolved from the daily
     * assignment log for that date when employee_id is not explicitly provided.
     * When charged_to=company an Expense row is auto-created and stored in
     * expense_id so the cost shows up in the expenses module.
     *
     * @param  array{fine_date: string, amount: string, description: string, authority?: string|null, charged_to?: string, employee_id?: int|null}  $data
     */
    public function logFine(Vehicle $vehicle, array $data): VehicleFine
    {
        return DB::transaction(function () use ($vehicle, $data): VehicleFine {
            // Auto-resolve driver from daily assignment log if not provided
            if (empty($data['employee_id'])) {
                $data['employee_id'] = $this->driverOnDate($vehicle, $data['fine_date']);
            }

            $fine = new VehicleFine(array_filter([
                'vehicle_id' => $vehicle->id,
                'employee_id' => $data['employee_id'] ?? null,
                'fine_date' => $data['fine_date'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'authority' => $data['authority'] ?? null,
                'charged_to' => $data['charged_to'] ?? 'company',
            ], fn ($v): bool => $v !== null));

            $fine->company_id = $vehicle->company_id;
            $fine->created_by = Auth::id();
            $fine->save();

            if ($fine->charged_to === 'company') {
                $this->createFineExpense($fine, $vehicle);
            }

            return $fine;
        });
    }

    public function deleteFine(VehicleFine $fine): void
    {
        DB::transaction(function () use ($fine): void {
            // Remove the linked expense first so the expenses module stays clean.
            if ($fine->expense_id !== null) {
                Expense::query()->withoutGlobalScope(CompanyScope::class)
                    ->whereKey($fine->expense_id)
                    ->delete();
            }

            $fine->delete();
        });
    }

    /**
     * Record a fuel fill-up.
     *
     * @param  array{fuel_date: string, litres: string, cost_per_litre: string, total_cost: string, employee_id?: int|null, mileage_at_fill?: int|null, payment_method?: string|null, notes?: string|null}  $data
     */
    public function logFuel(Vehicle $vehicle, array $data): VehicleFuelRecord
    {
        $record = new VehicleFuelRecord($data);
        $record->vehicle_id = $vehicle->id;
        $record->company_id = $vehicle->company_id;
        $record->created_by = Auth::id();
        $record->save();

        return $record;
    }

    public function deleteFuel(VehicleFuelRecord $record): void
    {
        $record->delete();
    }

    /**
     * Look up which employee had the vehicle on a given date from the daily
     * assignment log. Returns null when no entry exists for that date.
     */
    private function driverOnDate(Vehicle $vehicle, string $date): ?int
    {
        return VehicleDailyAssignment::withoutGlobalScope(CompanyScope::class)
            ->where('vehicle_id', $vehicle->id)
            ->where('assigned_date', $date)
            ->value('employee_id');
    }

    /**
     * Create (or refresh) an Expense row for a company-charged traffic fine.
     * Keyed off the fine's id so re-logging does not double-charge.
     */
    private function createFineExpense(VehicleFine $fine, Vehicle $vehicle): void
    {
        $plate = $vehicle->plate_number;
        $authority = $fine->authority ? " ({$fine->authority})" : '';

        $expense = new Expense;
        $expense->fill([
            'type' => ExpenseType::Other->value,
            'date' => $fine->fine_date->toDateString(),
            'subtotal' => (string) $fine->amount,
            'vat_rate' => null,
            'vat_amount' => '0',
            'total' => (string) $fine->amount,
            'payment_status' => PaymentStatus::Unpaid->value,
            'notes' => "Multa {$plate}{$authority}: {$fine->description}",
        ]);
        $expense->company_id = $vehicle->company_id;
        $expense->save();

        $fine->expense_id = $expense->id;
        $fine->save();
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
