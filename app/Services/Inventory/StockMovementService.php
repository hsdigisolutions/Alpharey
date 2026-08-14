<?php

namespace App\Services\Inventory;

use App\Enums\EquipmentAssignmentStatus;
use App\Enums\EquipmentIssueStatus;
use App\Enums\StockMovementType;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EquipmentItem;
use App\Models\EquipmentProjectAssignment;
use App\Models\EquipmentStockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Screen 23 — the stock ledger.
 *
 * equipment_stock_movements is the authority; an item's total_stock and
 * available_stock are its cached tail, and only this service writes them.
 * That is why those two columns are not mass assignable: a form that could
 * set them would let the counter and the ledger disagree with nothing to say
 * which is right.
 *
 * The two counters mean different things:
 *   total_stock     — everything the company owns
 *   available_stock — what is in the store and can be issued
 * so `total − available` is what is out with workers and sites.
 *
 *   stock_in    total +q   available +q   (bought)
 *   issue                  available −q   (out with a worker; still owned)
 *   return                 available +q   (came back)
 *   damaged     total −q   available −q   (written off out of the store)
 *   adjustment             available = q  (a recount; total moves by the
 *                                          same delta so what is out with
 *                                          workers is not silently rewritten)
 */
class StockMovementService
{
    /**
     * Record a movement and move the counters with it.
     *
     * @param  array<string, mixed>  $meta  employee_id / project_id / notes
     *
     * @throws ValidationException when the movement would drive stock negative
     */
    public function record(EquipmentItem $item, StockMovementType $type, float $quantity, array $meta = []): EquipmentStockMovement
    {
        if ($quantity <= 0 && $type !== StockMovementType::Adjustment) {
            throw ValidationException::withMessages([
                'quantity' => __('ui.inventory.quantity_positive'),
            ]);
        }

        return DB::transaction(function () use ($item, $type, $quantity, $meta): EquipmentStockMovement {
            // Lock the row: two concurrent issues of the last helmet must not
            // both read the same available_stock and both succeed.
            $item = EquipmentItem::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($item->id);

            $available = (float) $item->available_stock;
            $total = (float) $item->total_stock;

            [$newAvailable, $newTotal] = $this->apply($type, $quantity, $available, $total);

            if ($newAvailable < 0) {
                throw ValidationException::withMessages([
                    'quantity' => __('ui.inventory.insufficient_stock', ['available' => $available]),
                ]);
            }

            $movement = new EquipmentStockMovement([
                'equipment_item_id' => $item->id,
                'movement_type' => $type,
                'quantity' => (string) $quantity,
                'employee_id' => $meta['employee_id'] ?? null,
                'project_id' => $meta['project_id'] ?? null,
                'notes' => $meta['notes'] ?? null,
            ]);
            $movement->company_id = $item->company_id;
            $movement->created_by = Auth::id();
            // Frozen at the moment of the movement: this is what makes the
            // running balance reconstructable later, even if a counter is ever
            // repaired by hand.
            $movement->balance_after = (string) round($newAvailable, 2);
            $movement->save();

            $item->available_stock = (string) round($newAvailable, 2);
            $item->total_stock = (string) round(max(0, $newTotal), 2);
            $item->save();

            return $movement;
        });
    }

    /**
     * Issue kit to a worker: one ledger movement, one issue record, together.
     *
     * @param  array<string, mixed>  $data
     */
    public function issueTo(EquipmentItem $item, array $data): EmployeeEquipmentIssue
    {
        return DB::transaction(function () use ($item, $data): EmployeeEquipmentIssue {
            $quantity = (float) $data['issued_quantity'];

            $this->record($item, StockMovementType::Issue, $quantity, [
                'employee_id' => $data['employee_id'],
                'notes' => $data['notes'] ?? null,
            ]);

            $issue = new EmployeeEquipmentIssue([
                'employee_id' => $data['employee_id'],
                'equipment_item_id' => $item->id,
                'issued_quantity' => (string) $quantity,
                'issue_date' => $data['issue_date'] ?? now()->toDateString(),
                'expected_return_date' => $data['expected_return_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $issue->company_id = $item->company_id;
            $issue->status = EquipmentIssueStatus::Open;
            $issue->save();

            return $issue;
        });
    }

    /**
     * Assign kit to a site: one ledger movement (out of the store, still owned)
     * and one assignment record, together. Same shape as issueTo but keyed to a
     * project rather than a worker.
     *
     * @param  array<string, mixed>  $data
     */
    public function assignToProject(EquipmentItem $item, array $data): EquipmentProjectAssignment
    {
        return DB::transaction(function () use ($item, $data): EquipmentProjectAssignment {
            $quantity = (float) $data['quantity'];

            // Issue moves available down and leaves total — the company still
            // owns kit that is out on a site (refuses if the store is short).
            $this->record($item, StockMovementType::Issue, $quantity, [
                'project_id' => $data['project_id'],
                'notes' => $data['notes'] ?? null,
            ]);

            $assignment = new EquipmentProjectAssignment([
                'equipment_item_id' => $item->id,
                'project_id' => $data['project_id'],
                'quantity' => (string) $quantity,
                'start_date' => $data['start_date'] ?? now()->toDateString(),
                'end_date' => $data['end_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $assignment->company_id = $item->company_id;
            $assignment->status = EquipmentAssignmentStatus::Active;
            $assignment->save();

            return $assignment;
        });
    }

    /**
     * Take kit back from a site. A partial return is normal, so the assignment
     * stays Active until every unit is back, then it Completes with an end date.
     *
     * @throws ValidationException
     */
    public function returnFromProject(EquipmentProjectAssignment $assignment, float $quantity): EquipmentProjectAssignment
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'returned_quantity' => __('ui.inventory.quantity_positive'),
            ]);
        }

        if ($quantity > $assignment->outstanding()) {
            throw ValidationException::withMessages([
                'returned_quantity' => __('ui.inventory.return_exceeds_issued', [
                    'outstanding' => $assignment->outstanding(),
                ]),
            ]);
        }

        return DB::transaction(function () use ($assignment, $quantity): EquipmentProjectAssignment {
            $item = EquipmentItem::query()->withoutGlobalScopes()->findOrFail($assignment->equipment_item_id);

            $this->record($item, StockMovementType::Return, $quantity, [
                'project_id' => $assignment->project_id,
            ]);

            $assignment->returned_quantity = (string) round((float) $assignment->returned_quantity + $quantity, 2);

            $fullyReturned = $assignment->outstanding() <= 0;
            $assignment->status = $fullyReturned
                ? EquipmentAssignmentStatus::Completed
                : EquipmentAssignmentStatus::Active;
            if ($fullyReturned && $assignment->end_date === null) {
                $assignment->end_date = now();
            }
            $assignment->save();

            return $assignment;
        });
    }

    /**
     * Take kit back. A partial return is normal — a worker hands back three of
     * the five drills — so the issue stays open until the quantity matches.
     *
     * @throws ValidationException
     */
    public function returnFrom(EmployeeEquipmentIssue $issue, float $quantity): EmployeeEquipmentIssue
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'returned_quantity' => __('ui.inventory.quantity_positive'),
            ]);
        }

        if ($quantity > $issue->outstanding()) {
            throw ValidationException::withMessages([
                'returned_quantity' => __('ui.inventory.return_exceeds_issued', [
                    'outstanding' => $issue->outstanding(),
                ]),
            ]);
        }

        return DB::transaction(function () use ($issue, $quantity): EmployeeEquipmentIssue {
            $item = EquipmentItem::query()->withoutGlobalScopes()->findOrFail($issue->equipment_item_id);

            $this->record($item, StockMovementType::Return, $quantity, [
                'employee_id' => $issue->employee_id,
            ]);

            $issue->returned_quantity = (string) round((float) $issue->returned_quantity + $quantity, 2);

            $fullyReturned = $issue->outstanding() <= 0;
            $issue->status = $fullyReturned
                ? EquipmentIssueStatus::Returned
                : EquipmentIssueStatus::PartiallyReturned;

            // Only a completed return closes the issue with a date.
            $issue->return_date = $fullyReturned ? now() : null;
            $issue->save();

            return $issue;
        });
    }

    /**
     * How each movement type moves the two counters.
     *
     * @return array{0: float, 1: float} [available, total]
     */
    private function apply(StockMovementType $type, float $quantity, float $available, float $total): array
    {
        return match ($type) {
            StockMovementType::StockIn => [$available + $quantity, $total + $quantity],
            StockMovementType::Issue => [$available - $quantity, $total],
            StockMovementType::Return => [$available + $quantity, $total],
            StockMovementType::Damaged => [$available - $quantity, $total - $quantity],
            // A recount sets what is in the store; the same delta moves the
            // total so the quantity out with workers survives untouched.
            StockMovementType::Adjustment => [$quantity, $total + ($quantity - $available)],
        };
    }
}
