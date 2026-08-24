<?php

namespace App\Services\Vehicles;

use App\Enums\BearableBy;
use App\Enums\VehicleExpenseType;
use App\Models\Expense;
use App\Models\Scopes\CompanyScope;
use App\Models\Vehicle;
use App\Models\VehicleFine;
use App\Models\VehicleFuelRecord;
use App\Models\VehicleMaintenanceHistory;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;

/**
 * On an expense's FINAL approval, mirror a vehicle-linked expense into the
 * matching vehicle_* table (Part E) so it shows on the vehicle's Fuel / Fine /
 * Maintenance tab — linked back via expense_id. The vehicle_* tables stay the
 * single query path for those tabs (write the row, don't union).
 *
 * Keyed on expenses.vehicle_expense_type (set by the worker-fuel mirror and by
 * Part D direct entry). Idempotent: a vehicle_* row already linked to this
 * expense is never duplicated.
 */
class VehicleExpenseSyncService
{
    public function syncOnFinalApproval(Expense $expense): void
    {
        if (! $expense->approved || $expense->vehicle_id === null) {
            return;
        }

        match ($expense->vehicle_expense_type) {
            VehicleExpenseType::Fuel->value => $this->syncFuel($expense),
            VehicleExpenseType::Fine->value => $this->syncFine($expense),
            VehicleExpenseType::Maintenance->value => $this->syncMaintenance($expense),
            default => null,
        };
    }

    private function syncFuel(Expense $expense): void
    {
        if ($this->alreadySynced(VehicleFuelRecord::query(), $expense)) {
            return;
        }

        $record = new VehicleFuelRecord([
            'vehicle_id' => $expense->vehicle_id,
            'employee_id' => $expense->employee_id,
            'fuel_date' => $expense->date->toDateString(),
            'litres' => '0',
            'cost_per_litre' => '0',
            'total_cost' => (string) $expense->total,
            'payment_method' => 'reimburse',
            'notes' => $expense->notes,
        ]);
        $record->company_id = (int) $expense->company_id;
        $record->expense_id = $expense->id;
        $record->save();
    }

    private function syncFine(Expense $expense): void
    {
        if ($this->alreadySynced(VehicleFine::query(), $expense)) {
            return;
        }

        // Who bears it: employee-borne → charged to the driver (and deductible);
        // otherwise the company.
        $employeeBorne = $expense->bearable_by === BearableBy::Employee;

        $fine = new VehicleFine([
            'vehicle_id' => $expense->vehicle_id,
            'employee_id' => $expense->employee_id,
            'fine_date' => $expense->date->toDateString(),
            'amount' => (string) $expense->total,
            'description' => (string) ($expense->notes ?? ''),
        ]);
        $fine->company_id = (int) $expense->company_id;
        $fine->charged_to = $employeeBorne ? 'employee' : 'company';
        $fine->deduct_from_salary = $employeeBorne && $expense->deduct_from_salary;
        $fine->expense_id = $expense->id;
        $fine->save();
    }

    private function syncMaintenance(Expense $expense): void
    {
        if ($this->alreadySynced(VehicleMaintenanceHistory::query(), $expense)) {
            return;
        }

        $vendorName = $expense->vendor_id !== null
            ? Vendor::query()->whereKey($expense->vendor_id)->value('name')
            : null;

        $record = new VehicleMaintenanceHistory([
            'vehicle_id' => $expense->vehicle_id,
            'maintenance_type' => 'general',
            'maintenance_date' => $expense->date->toDateString(),
            'description' => (string) ($expense->notes ?? ''),
            'vendor_name' => $vendorName,
            'cost' => (string) $expense->total,
        ]);
        // vehicle_maintenance_histories has no company_id — it is scoped via the
        // vehicle. Only the expense link + created_by are server-set here.
        $record->expense_id = $expense->id;
        $record->save();

        // Keep the vehicle's maintenance rollup in step (recomputed, scope-free
        // so an SA approving from the cross-company review queue still sums right).
        $vehicle = Vehicle::query()->withoutGlobalScope(CompanyScope::class)->find($expense->vehicle_id);
        if ($vehicle !== null) {
            $total = (float) VehicleMaintenanceHistory::query()->withoutGlobalScope(CompanyScope::class)
                ->where('vehicle_id', $vehicle->id)->sum('cost');
            $vehicle->maintenance_cost_total = (string) round($total, 2);
            $vehicle->save();
        }
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function alreadySynced(Builder $query, Expense $expense): bool
    {
        return $query->withoutGlobalScope(CompanyScope::class)
            ->where('expense_id', $expense->id)
            ->exists();
    }
}
