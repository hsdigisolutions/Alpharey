<?php

namespace App\Services\Workers;

use App\Enums\BearableBy;
use App\Enums\ExpenseType;
use App\Enums\PaymentStatus;
use App\Enums\WorkerExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Scopes\CompanyScope;
use App\Models\Vehicle;
use App\Models\WorkerExpense;

/**
 * When a manager APPROVES a worker's expense (fuel or any category), mirror it
 * into the company expenses module as a real, employee-borne reimbursable
 * Expense — the SINGLE source of truth for the money (Change: vehicle-expense
 * flow, Part B). The mirror starts UNAPPROVED; only the Admin's final approval
 * in the Expenses tab (expenses.approve_final) flips it approved, and ONLY then
 * does payroll count it. This is the manager→admin two-gate separation of
 * duties — payroll never counts at the manager step.
 *
 * Rules:
 *  - fires for ANY category once status = approved;
 *  - carries employee_id / project_id / vehicle_id + is_reimbursable so payroll
 *    reads it through the normal reimbursement path (which requires approved);
 *  - idempotent via auto_expense_id (never double-creates);
 *  - starts approved = false (the Admin gate), so payroll ignores it until then;
 *  - legacy worker expenses that never got a mirror keep being counted by
 *    PayrollService::pwaExpensesFor (which now skips mirrored ones), so no money
 *    vanishes and none double-counts.
 */
class WorkerFuelExpenseService
{
    /** Fuel/vehicle-linked worker expenses (feed the vehicle Fuel tab on final approval). */
    public const SOURCE = 'worker_fuel';

    /** Any other worker-submitted expense. */
    public const SOURCE_GENERAL = 'worker_expense';

    /**
     * Create the company expense for a just-approved worker expense.
     * Returns the created (or already-linked) Expense, or null when it does not
     * apply.
     */
    public function syncApproved(WorkerExpense $workerExpense): ?Expense
    {
        if ($workerExpense->status !== WorkerExpenseStatus::Approved) {
            return null;
        }
        if ($workerExpense->auto_expense_id !== null) {
            return null; // idempotent — already mirrored
        }

        $companyId = (int) $workerExpense->company_id;
        $isFuel = $workerExpense->category === 'fuel';
        $category = $this->categoryFor($companyId, $isFuel);

        $expense = new Expense;
        $expense->fill([
            'type' => ExpenseType::Other->value,
            'expense_category_id' => $category->id,
            'project_id' => $workerExpense->project_id,
            'employee_id' => $workerExpense->employee_id,
            'date' => $workerExpense->date->toDateString(),
            'subtotal' => (string) $workerExpense->amount,
            'vat_rate' => null,
            'vat_amount' => '0',
            'total' => (string) $workerExpense->amount,
            'payment_status' => PaymentStatus::Unpaid->value,
            // Employee fronted it → reimbursed on FINAL approval (not deducted).
            'bearable_by' => BearableBy::Employee->value,
            'is_reimbursable' => true,
            'deduct_from_salary' => false,
            'notes' => $this->describe($workerExpense, $isFuel).' · #'.$workerExpense->id,
        ]);
        // Server-set columns (never mass-assignable).
        $expense->company_id = $companyId;
        $expense->vehicle_id = $workerExpense->vehicle_id;
        $expense->source = $isFuel ? self::SOURCE : self::SOURCE_GENERAL;
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

    /** A readable label: "Combustible — <plate/name> — <worker>" / "<desc> — <worker>". */
    private function describe(WorkerExpense $workerExpense, bool $isFuel): string
    {
        $parts = [$isFuel ? 'Combustible' : ($workerExpense->description !== '' ? $workerExpense->description : 'Gasto de trabajador')];

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

    /** The company's Fuel / generic worker-expense category, created on first use. */
    private function categoryFor(int $companyId, bool $isFuel): ExpenseCategory
    {
        return ExpenseCategory::query()->firstOrCreate(
            ['company_id' => $companyId, 'name' => $isFuel ? 'Combustible' : 'Gasto de trabajador'],
            ['active' => true],
        );
    }
}
