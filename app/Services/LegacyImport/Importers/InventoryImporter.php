<?php

namespace App\Services\LegacyImport\Importers;

use App\Enums\EquipmentAssignmentStatus;
use App\Enums\EquipmentIssueStatus;
use App\Enums\EquipmentItemType;
use App\Enums\StockMovementType;
use App\Models\Company;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\EquipmentProjectAssignment;
use App\Models\EquipmentStockMovement;
use App\Services\LegacyImport\AbstractImporter;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy equipment tables → the new inventory (DATA_MIGRATION.md §3.9).
 *
 * The stock counters migrate VERBATIM and the movements are NOT replayed
 * through StockMovementService. That looks like it contradicts "the ledger is
 * the authority", so it is worth being explicit: replaying would recompute
 * every balance_after from an opening stock the legacy data does not record,
 * and any gap in the old ledger (a movement someone deleted, a counter
 * corrected by hand) would surface as a stock figure that disagrees with the
 * warehouse. What is on the shelf today is the fact; the ledger becomes the
 * authority from the first movement the new system writes.
 */
class InventoryImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'inventory';
    }

    protected function import(): void
    {
        $defaultCompanyId = Company::query()->orderBy('id')->value('id');

        if ($defaultCompanyId === null) {
            $this->exception('inventory', null, 'No companies exist — run the seeder first.');

            return;
        }

        $this->importCategories($defaultCompanyId);
        $this->importItems($defaultCompanyId);
        $this->importMovements($defaultCompanyId);
        $this->importIssues($defaultCompanyId);
        $this->importAssignments($defaultCompanyId);
    }

    private function importCategories(int $companyId): void
    {
        if (! Schema::connection('legacy')->hasTable('equipment_categories')) {
            return;
        }

        foreach ($this->legacy('equipment_categories')->orderBy('id')->get() as $row) {
            if ($this->alreadyImported($row->id, 'equipment_categories')) {
                continue;
            }

            $category = EquipmentCategory::query()->create([
                'company_id' => $companyId,
                'name' => (string) ($row->name ?? 'Sin nombre'),
                'description' => $row->description ?? null,
                'active' => (bool) ($row->active ?? true),
            ]);

            $this->recordMapping($row->id, $category->id, 'equipment_categories');
        }
    }

    private function importItems(int $companyId): void
    {
        $this->legacy('equipment_items')->orderBy('id')->chunk(500, function ($rows) use ($companyId): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                $item = new EquipmentItem([
                    'equipment_category_id' => ($row->equipment_category_id ?? null) !== null
                        ? $this->newIdFor($row->equipment_category_id, 'equipment_categories')
                        : null,
                    'name' => (string) ($row->name ?? 'Sin nombre'),
                    'sku' => (string) ($row->sku ?? 'LEGACY-'.$row->id),
                    'item_type' => $this->normalizeType($row->item_type ?? null)->value,
                    'unit' => (string) ($row->unit ?? 'pcs'),
                    'active' => (bool) ($row->active ?? true),
                    'notes' => $row->notes ?? null,
                ]);
                $item->company_id = $companyId;
                // Not mass assignable (the ledger owns them) — and carried over
                // as stored: what is on the shelf today is the fact.
                $item->total_stock = (string) ($row->total_stock ?? 0);
                $item->available_stock = (string) ($row->available_stock ?? 0);
                $item->save();

                $this->recordMapping($row->id, $item->id);
                $this->imported++;
            }
        });
    }

    private function importMovements(int $companyId): void
    {
        if (! Schema::connection('legacy')->hasTable('equipment_stock_movements')) {
            return;
        }

        $this->legacy('equipment_stock_movements')->orderBy('id')->chunk(500, function ($rows) use ($companyId): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id, 'equipment_stock_movements')) {
                    continue;
                }

                $itemId = $this->newIdFor($row->equipment_item_id, 'equipment_items');

                if ($itemId === null) {
                    $this->exception('equipment_stock_movements', $row->id, 'Item not imported — run the items step first', [
                        'legacy_item_id' => $row->equipment_item_id,
                    ]);

                    continue;
                }

                $movement = new EquipmentStockMovement([
                    'equipment_item_id' => $itemId,
                    'movement_type' => $this->normalizeMovement($row->movement_type ?? null)->value,
                    'quantity' => (string) ($row->quantity ?? 0),
                    'employee_id' => ($row->employee_id ?? null) !== null
                        ? $this->newIdFor($row->employee_id, 'employees')
                        : null,
                    'project_id' => ($row->project_id ?? null) !== null
                        ? $this->newIdFor($row->project_id, 'projects')
                        : null,
                    'notes' => $row->notes ?? null,
                ]);
                $movement->company_id = $companyId;
                // The historical running balance, exactly as recorded — never
                // recomputed (see the class docblock).
                $movement->balance_after = ($row->balance_after ?? null) !== null
                    ? (string) $row->balance_after
                    : null;
                $movement->save();

                $this->recordMapping($row->id, $movement->id, 'equipment_stock_movements');
            }
        });
    }

    private function importIssues(int $companyId): void
    {
        if (! Schema::connection('legacy')->hasTable('employee_equipment_issues')) {
            return;
        }

        foreach ($this->legacy('employee_equipment_issues')->orderBy('id')->get() as $row) {
            if ($this->alreadyImported($row->id, 'employee_equipment_issues')) {
                continue;
            }

            $employeeId = $this->newIdFor($row->employee_id, 'employees');
            $itemId = $this->newIdFor($row->equipment_item_id, 'equipment_items');

            if ($employeeId === null || $itemId === null) {
                $this->exception('employee_equipment_issues', $row->id, 'Employee or item not imported — run those steps first', [
                    'legacy_employee_id' => $row->employee_id,
                    'legacy_item_id' => $row->equipment_item_id,
                ]);

                continue;
            }

            $issue = new EmployeeEquipmentIssue([
                'employee_id' => $employeeId,
                'equipment_item_id' => $itemId,
                'issued_quantity' => (string) ($row->issued_quantity ?? 0),
                'issue_date' => $row->issue_date,
                'expected_return_date' => $row->expected_return_date ?? null,
                'notes' => $row->notes ?? null,
            ]);
            $issue->company_id = $companyId;
            // Not mass assignable — the return path owns these.
            $issue->returned_quantity = (string) ($row->returned_quantity ?? 0);
            $issue->return_date = $row->return_date ?? null;
            $issue->status = $this->normalizeIssueStatus($row->status ?? null);
            $issue->save();

            $this->recordMapping($row->id, $issue->id, 'employee_equipment_issues');
        }
    }

    private function importAssignments(int $companyId): void
    {
        if (! Schema::connection('legacy')->hasTable('equipment_project_assignments')) {
            return;
        }

        foreach ($this->legacy('equipment_project_assignments')->orderBy('id')->get() as $row) {
            if ($this->alreadyImported($row->id, 'equipment_project_assignments')) {
                continue;
            }

            $itemId = $this->newIdFor($row->equipment_item_id, 'equipment_items');
            $projectId = $this->newIdFor($row->project_id, 'projects');

            if ($itemId === null || $projectId === null) {
                $this->exception('equipment_project_assignments', $row->id, 'Item or project not imported — run those steps first', [
                    'legacy_item_id' => $row->equipment_item_id,
                    'legacy_project_id' => $row->project_id,
                ]);

                continue;
            }

            $assignment = new EquipmentProjectAssignment([
                'equipment_item_id' => $itemId,
                'project_id' => $projectId,
                'quantity' => (string) ($row->quantity ?? 1),
                'start_date' => $row->start_date,
                'end_date' => $row->end_date ?? null,
                'notes' => $row->notes ?? null,
            ]);
            $assignment->company_id = $companyId;
            $assignment->status = EquipmentAssignmentStatus::tryFrom((string) ($row->status ?? ''))
                ?? EquipmentAssignmentStatus::Active;
            $assignment->save();

            $this->recordMapping($row->id, $assignment->id, 'equipment_project_assignments');
        }
    }

    private function normalizeType(?string $legacy): EquipmentItemType
    {
        return EquipmentItemType::tryFrom((string) $legacy) ?? EquipmentItemType::Tool;
    }

    private function normalizeMovement(?string $legacy): StockMovementType
    {
        return StockMovementType::tryFrom((string) $legacy) ?? StockMovementType::Adjustment;
    }

    private function normalizeIssueStatus(?string $legacy): EquipmentIssueStatus
    {
        return EquipmentIssueStatus::tryFrom((string) $legacy) ?? EquipmentIssueStatus::Open;
    }
}
