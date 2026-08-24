<?php

namespace App\Services\Vehicles;

use App\Models\Expense;
use App\Models\Scopes\CompanyScope;
use App\Models\VehicleFuelRecord;
use App\Services\Workers\WorkerFuelExpenseService;

/**
 * On an expense's FINAL approval, mirror a vehicle-linked expense into the
 * matching vehicle_* table (Part E) so it shows on the vehicle's Fuel / Fine /
 * Maintenance tab — linked back via expense_id. The vehicle_* tables stay the
 * single query path for those tabs (Q3: write the row, don't union expenses).
 *
 * Idempotent: a vehicle_* row already linked to this expense is never
 * duplicated (re-approving is safe). Works for both worker-PWA and (Part D)
 * admin-created vehicle expenses.
 *
 * Today the only fully-wired source is worker fuel (source worker_fuel) →
 * VehicleFuelRecord. Direct fine/maintenance entry (Part D) will extend this.
 */
class VehicleExpenseSyncService
{
    public function syncOnFinalApproval(Expense $expense): void
    {
        if (! $expense->approved || $expense->vehicle_id === null) {
            return;
        }

        // Fuel: worker-PWA fuel mirror (Part D adds fine/maintenance via type).
        if ($expense->source === WorkerFuelExpenseService::SOURCE) {
            $this->syncFuel($expense);
        }
    }

    private function syncFuel(Expense $expense): void
    {
        $exists = VehicleFuelRecord::query()->withoutGlobalScope(CompanyScope::class)
            ->where('expense_id', $expense->id)
            ->exists();
        if ($exists) {
            return; // idempotent
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
        // Server-set (never mass-assignable).
        $record->company_id = (int) $expense->company_id;
        $record->expense_id = $expense->id;
        $record->save();
    }
}
