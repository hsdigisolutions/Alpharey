<?php

namespace App\Services\Workers;

use App\Enums\BearableBy;
use App\Enums\ExpenseType;
use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Enums\WorkerExpenseStatus;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Scopes\CompanyScope;
use App\Models\Vehicle;
use App\Models\WorkerExpense;
use App\Services\Notifications\NotificationDispatcher;

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

        return $this->createMirror($workerExpense, null);
    }

    /**
     * Item 5 — mint the mirror Expense the moment a worker submits from the PWA,
     * as UNAPPROVED / pending (review_status null), so the submission shows in the
     * regular Expenses tab immediately (the Worker Expenses admin tab is gone).
     * Idempotent (createMirror no-ops if already mirrored). It is bearable_by =
     * employee + is_reimbursable, so on FINAL approval payroll reimburses the
     * worker once + it books as a project cost once — and pwaExpensesFor never
     * counts it (auto_expense_id now set), so there is no double count.
     */
    public function mirrorOnSubmission(WorkerExpense $workerExpense): ?Expense
    {
        return $this->createMirror($workerExpense, null);
    }

    /**
     * Create the mirror Expense in the "in review" state — the manager sent the
     * worker expense to the Super-Admin review queue instead of approving it.
     * The mirror is unapproved with review_status = in_review, and carries WHO
     * escalated it + any note (BUG 4 — the review queue shows both).
     */
    public function mirrorForReview(WorkerExpense $workerExpense, ?int $escalatedBy = null, ?string $note = null): ?Expense
    {
        return $this->createMirror($workerExpense, 'in_review', $escalatedBy, $note);
    }

    private function createMirror(WorkerExpense $workerExpense, ?string $reviewStatus, ?int $escalatedBy = null, ?string $note = null): ?Expense
    {
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
        $expense->vehicle_expense_type = $isFuel ? 'fuel' : null;
        $expense->source = $isFuel ? self::SOURCE : self::SOURCE_GENERAL;
        $expense->source_id = $workerExpense->id;
        $expense->review_status = $reviewStatus;
        $expense->escalated_by = $reviewStatus === 'in_review' ? $escalatedBy : null;
        $expense->review_note = $reviewStatus === 'in_review' ? $note : null;
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

        // Resolve the worker unscoped — the mirror is minted at submission time
        // (worker PWA session / backfill), where the CompanyScope would hide the
        // employee and drop the name from the note. Same pattern as the vehicle.
        $worker = Employee::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $workerExpense->company_id)
            ->find($workerExpense->employee_id)?->full_name;
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

    /**
     * The WorkerExpense a mirror Expense was created from (source_id link), or
     * null when the expense is not a worker mirror. Scope dropped — the decider
     * may be a Super Admin acting across companies.
     */
    public function linkedWorkerExpense(Expense $expense): ?WorkerExpense
    {
        if (! in_array($expense->source, [self::SOURCE, self::SOURCE_GENERAL], true) || $expense->source_id === null) {
            return null;
        }

        return WorkerExpense::query()->withoutGlobalScope(CompanyScope::class)->find($expense->source_id);
    }

    /**
     * The mirror was escalated to review → the WorkerExpense reflects it as
     * in_review, with NO approver stamped (it was NOT approved — BUG 3). Keeps
     * the Worker Expenses tab truthful about the real (pending-SA) state.
     */
    public function markWorkerInReview(Expense $expense): void
    {
        $workerExpense = $this->linkedWorkerExpense($expense);
        if ($workerExpense === null) {
            return;
        }

        $workerExpense->status = WorkerExpenseStatus::InReview;
        $workerExpense->approved_by = null;
        $workerExpense->approved_at = null;
        $workerExpense->rejection_reason = null;
        $workerExpense->save();
    }

    /**
     * The Super Admin's FINAL decision on the mirror cascades to the linked
     * WorkerExpense so the Worker Expenses tab, and the worker's own view, show
     * the true outcome — and the worker is notified. Money is NOT touched here:
     * payroll counts the mirror Expense via its own `approved` flag (a mirrored
     * WorkerExpense is excluded from PayrollService::pwaExpensesFor), so this is
     * a status/tracking sync only.
     */
    public function applyFinalDecisionToWorker(Expense $expense, bool $approved, ?int $deciderId): void
    {
        $workerExpense = $this->linkedWorkerExpense($expense);
        if ($workerExpense === null) {
            return;
        }

        $newStatus = $approved ? WorkerExpenseStatus::Approved : WorkerExpenseStatus::Rejected;
        $changed = $workerExpense->status !== $newStatus;

        $workerExpense->status = $newStatus;
        $workerExpense->approved_by = $deciderId;
        $workerExpense->approved_at = now();
        $workerExpense->save();

        // Notify only on a real transition — a worker escalated then approved is
        // told once; the normal manager→admin path (already Approved) won't
        // double-notify.
        if ($changed) {
            app(NotificationDispatcher::class)->dispatchToUser(
                NotificationType::ExpenseDecided,
                $workerExpense->employee?->user,
                [
                    'title_es' => $approved ? 'Tu gasto fue aprobado' : 'Tu gasto fue rechazado',
                    'title_en' => $approved ? 'Your expense was approved' : 'Your expense was rejected',
                    'entity' => $workerExpense->description,
                    'url' => '/worker',
                ],
            );
        }
    }
}
