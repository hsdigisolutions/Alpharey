<?php

namespace App\Services\Deployments;

use App\Enums\BillingMethod;
use App\Enums\DeploymentRateType;
use App\Models\Attendance;
use App\Models\DeploymentCharge;
use App\Models\EmployeeDeployment;

/**
 * Option A cross-charge engine (PAYROLL_DEPLOYMENTS.md). The employee stays
 * on their HOME company's payroll; the HOST company is charged an internal
 * cross-charge for using them, computed from the hours/days the employee
 * logged against the HOST project during the deployment period × the
 * deployment rate × the host split %.
 *
 * ONLY Option A is automated. Option B (host processes payroll) is never
 * implemented — cesión ilegal (dev skill Rule 13). Option C would reuse this
 * with a split % < 100; not automated in this phase.
 */
class DeploymentChargeService
{
    /**
     * Units (hours or days) the employee logged on the host project within
     * the deployment window — the billable basis for the cross-charge.
     */
    public function accruedUnits(EmployeeDeployment $deployment): float
    {
        $end = $deployment->deployment_end?->toDateString() ?? now()->toDateString();

        $records = Attendance::query()
            ->withoutGlobalScopes()
            ->where('employee_id', $deployment->employee_id)
            ->where('project_id', $deployment->project_id)
            ->whereBetween('date', [$deployment->deployment_start->toDateString(), $end])
            ->get();

        return match ($deployment->rate_type) {
            DeploymentRateType::Daily => (float) $records->count(),
            // hourly / per_meter both bill on logged hours here (meters would
            // read measurements — deferred until measurement billing, Phase 6)
            default => round((float) $records->sum(fn (Attendance $r) => (float) $r->hours_worked), 2),
        };
    }

    /**
     * Current accrued cost for an active deployment (live, not persisted).
     */
    public function accruedAmount(EmployeeDeployment $deployment): float
    {
        $units = $this->accruedUnits($deployment);
        $rate = (float) ($deployment->rate_during_deployment ?? 0);
        $split = (float) $deployment->split_pct / 100;

        return round($units * $rate * $split, 2);
    }

    /**
     * Generate (or refresh) the persisted cross-charge for a deployment —
     * called when it completes. Only Option A produces an automated charge.
     */
    public function generateCharge(EmployeeDeployment $deployment): ?DeploymentCharge
    {
        if ($deployment->billing_method !== BillingMethod::OptionA) {
            return null;
        }

        $units = $this->accruedUnits($deployment);
        $rate = (float) ($deployment->rate_during_deployment ?? 0);
        $split = (float) $deployment->split_pct / 100;
        $amount = round($units * $rate * $split, 2);

        return DeploymentCharge::query()->updateOrCreate(
            ['employee_deployment_id' => $deployment->id],
            [
                'home_company_id' => $deployment->home_company_id,
                'host_company_id' => $deployment->host_company_id,
                'project_id' => $deployment->project_id,
                'period_start' => $deployment->deployment_start->toDateString(),
                'period_end' => $deployment->deployment_end?->toDateString() ?? now()->toDateString(),
                'units' => (string) $units,
                'rate_type' => $deployment->rate_type->value,
                'rate' => (string) $rate,
                'amount' => (string) $amount,
                'status' => 'pending',
            ],
        );
    }
}
