<?php

namespace App\Services\Deployments;

use App\Enums\BillingMethod;
use App\Enums\DeploymentRateType;
use App\Enums\ExpenseType;
use App\Models\Attendance;
use App\Models\DeploymentCharge;
use App\Models\EmployeeDeployment;
use App\Models\Expense;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

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
        $periodEnd = $deployment->deployment_end?->toDateString() ?? now()->toDateString();

        return DB::transaction(function () use ($deployment, $units, $rate, $amount, $periodEnd): DeploymentCharge {
            $charge = DeploymentCharge::query()->updateOrCreate(
                ['employee_deployment_id' => $deployment->id],
                [
                    'home_company_id' => $deployment->home_company_id,
                    'host_company_id' => $deployment->host_company_id,
                    'project_id' => $deployment->project_id,
                    'period_start' => $deployment->deployment_start->toDateString(),
                    'period_end' => $periodEnd,
                    'units' => (string) $units,
                    'rate_type' => $deployment->rate_type->value,
                    'rate' => (string) $rate,
                    'amount' => (string) $amount,
                    'status' => 'pending',
                ],
            );

            $this->postHostExpense($charge, $deployment, $amount, $periodEnd);

            return $charge;
        });
    }

    /**
     * Post (or refresh) the internal expense the charge creates on the HOST
     * company — PAYROLL_DEPLOYMENTS.md step 4.
     *
     * Three things make this safe:
     *  - it is keyed off the charge's own expense_id, so re-running the engine
     *    UPDATES the same expense instead of double-charging the host;
     *  - company_id is set to the HOST explicitly, never from the session: the
     *    person completing the deployment may be acting for the home company;
     *  - no vendor and no VAT. It carries no vendor so it stays out of vendor
     *    expense reports, and the client confirmed there is no inter-company
     *    VAT invoice for now (DECISIONS.md / PAYROLL_DEPLOYMENTS.md decision 2).
     */
    private function postHostExpense(
        DeploymentCharge $charge,
        EmployeeDeployment $deployment,
        float $amount,
        string $periodEnd,
    ): void {
        $expense = $charge->expense_id !== null
            ? Expense::query()->withoutGlobalScope(CompanyScope::class)->find($charge->expense_id)
            : null;

        $expense ??= new Expense;

        $expense->fill([
            'type' => ExpenseType::InternalDeployment->value,
            'project_id' => $deployment->project_id,
            'date' => $periodEnd,
            'subtotal' => (string) $amount,
            'vat_rate' => null,
            'vat_amount' => '0',
            'total' => (string) $amount,
            // The employee relation drops all global scopes, so it resolves
            // even for a soft-deleted worker — never null here.
            'notes' => __('ui.deployments.charge_expense_note', [
                'employee' => $deployment->employee->full_name,
            ]),
        ]);

        // company_id is not mass assignable — and must be the HOST, not the
        // acting company (Rule 1 opt-out for legitimate cross-company work).
        $expense->company_id = $deployment->host_company_id;
        $expense->save();

        if ($charge->expense_id !== $expense->id) {
            $charge->expense_id = $expense->id;
            $charge->save();
        }
    }
}
