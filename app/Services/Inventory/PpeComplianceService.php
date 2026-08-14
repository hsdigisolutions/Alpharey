<?php

namespace App\Services\Inventory;

use App\Enums\EquipmentIssueStatus;
use App\Models\Employee;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EquipmentCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * PPE (EPI) compliance for a worker (Phase D). Required PPE is defined by
 * CATEGORY (a category flagged `is_required_ppe`); a `height_only` category
 * (arnés) is required only for a worker who `works_at_height`. A worker "has" a
 * required category if they currently hold an item in it; the row reads Valid,
 * Expired (held but past its expiry) or Missing. Alerts only — never a hard
 * block (client decision Q3).
 */
class PpeComplianceService
{
    /**
     * @param  bool  $ownRequiredOnly  restrict to the company's OWN required-PPE
     *                                 categories (exclude the shared group defaults). The proactive missing-PPE
     *                                 ALERT is opt-in per company this way; the on-screen report uses defaults
     *                                 too (so a worker sees the baseline EPIs even before a company configures
     *                                 its own).
     * @return list<array<string, mixed>>
     */
    public function forEmployee(Employee $employee, bool $ownRequiredOnly = false): array
    {
        $required = EquipmentCategory::query()
            ->when(
                $ownRequiredOnly,
                fn (Builder $q) => $q->where('company_id', $employee->company_id),
                fn (Builder $q) => $q->forCompany($employee->company_id),
            )
            ->where('is_required_ppe', true)
            ->where('active', true)
            // A height-only requirement (arnés) applies only to height workers.
            ->when(! $employee->works_at_height, fn (Builder $q) => $q->where('height_only', false))
            ->orderBy('name')
            ->get();

        if ($required->isEmpty()) {
            return [];
        }

        // The worker's current kit (scope dropped so a deployed/PWA context still
        // resolves; item loaded unscoped for its category even if soft-deleted).
        $current = EmployeeEquipmentIssue::query()
            ->withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('status', '!=', EquipmentIssueStatus::Returned->value)
            ->with(['item' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'equipment_category_id')])
            ->get();

        return $required->map(function (EquipmentCategory $cat) use ($current): array {
            /** @var Collection<int, EmployeeEquipmentIssue> $held */
            $held = $current->filter(fn (EmployeeEquipmentIssue $i) => $i->item?->equipment_category_id === $cat->id);

            if ($held->isEmpty()) {
                return ['category' => $cat->name, 'has' => false, 'expiry_date' => null, 'status' => 'missing'];
            }

            // Prefer a still-valid holding; otherwise surface the expired one.
            $chosen = $held->first(fn (EmployeeEquipmentIssue $i) => ! $i->isExpired()) ?? $held->first();

            return [
                'category' => $cat->name,
                'has' => true,
                'expiry_date' => $chosen->expiry_date?->toDateString(),
                'status' => $chosen->isExpired() ? 'expired' : 'valid',
            ];
        })->values()->all();
    }
}
