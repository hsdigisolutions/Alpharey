<?php

namespace App\Services\Workers;

use App\Enums\ExpenseType;
use App\Enums\PaymentStatus;
use App\Enums\WorkerExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Scopes\CompanyScope;
use App\Models\Vehicle;
use App\Models\WorkerExpense;

/**
 * When an admin APPROVES a worker's fuel expense, mirror it into the company
 * expenses module as a real Expense so the fuel cost is visible there without
 * re-keying.
 *
 * Rules (client spec):
 *  - fires ONLY for category = fuel AND status = approved;
 *  - idempotent — a worker expense that already has an auto_expense_id is left
 *    alone, so re-approving (or approving twice) never double-creates;
 *  - un-approving does NOT delete the auto expense (admin removes it by hand);
 *  - the created expense is NOT approved (like the vehicle-fine auto-expense) so
 *    it never double-counts against approved-expense report sums.
 */
class WorkerFuelExpenseService
{
    public const SOURCE = 'worker_fuel';

    /**
     * Create the company expense for a just-approved fuel worker expense.
     * Returns the created (or already-linked) Expense, or null when it does not
     * apply.
     */
    public function syncApproved(WorkerExpense $workerExpense): ?Expense
    {
        if ($workerExpense->category !== 'fuel') {
            return null;
        }
        if ($workerExpense->status !== WorkerExpenseStatus::Approved) {
            return null;
        }
        if ($workerExpense->auto_expense_id !== null) {
            return null; // idempotent — already mirrored
        }

        $companyId = (int) $workerExpense->company_id;
        $category = $this->fuelCategory($companyId);
        $label = $this->describe($workerExpense);

        $expense = new Expense;
        $expense->fill([
            'type' => ExpenseType::Other->value,
            'expense_category_id' => $category->id,
            'date' => $workerExpense->date->toDateString(),
            'subtotal' => (string) $workerExpense->amount,
            'vat_rate' => null,
            'vat_amount' => '0',
            'total' => (string) $workerExpense->amount,
            'payment_status' => PaymentStatus::Unpaid->value,
            'notes' => $label.' · Auto-creado del gasto de combustible del trabajador #'.$workerExpense->id,
        ]);
        // Server-set columns (never mass-assignable).
        $expense->company_id = $companyId;
        $expense->source = self::SOURCE;
        $expense->source_id = $workerExpense->id;
        // Reference the SAME receipt file (not copied).
        if ($workerExpense->receipt_path !== null) {
            $expense->file_path = $workerExpense->receipt_path;
        }
        $expense->save();

        // Link back + guard against a second create.
        $workerExpense->auto_expense_id = $expense->id;
        $workerExpense->saveQuietly();

        return $expense;
    }

    /** "Combustible — <plate/name> — <worker>" (vehicle part omitted if unknown). */
    private function describe(WorkerExpense $workerExpense): string
    {
        $parts = ['Combustible'];

        if ($workerExpense->vehicle_id !== null) {
            $vehicle = Vehicle::query()->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $workerExpense->company_id)
                ->find($workerExpense->vehicle_id);
            $name = $vehicle !== null
                ? trim($vehicle->plate_number.' '.trim(($vehicle->brand ?? '').' '.($vehicle->model ?? '')))
                : null;
            if ($name !== null && $name !== '') {
                $parts[] = $name;
            }
        }

        $worker = $workerExpense->employee?->full_name;
        if ($worker !== null && $worker !== '') {
            $parts[] = $worker;
        }

        return implode(' — ', $parts);
    }

    /** The company's Fuel expense category, created on first use. */
    private function fuelCategory(int $companyId): ExpenseCategory
    {
        return ExpenseCategory::query()->firstOrCreate(
            ['company_id' => $companyId, 'name' => 'Combustible'],
            ['active' => true],
        );
    }
}
