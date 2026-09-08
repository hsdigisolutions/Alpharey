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
use Illuminate\Support\Collection;
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
     * The employee's host-project attendance rows within the deployment window
     * — the billable basis for the cross-charge. Worked days only; a plain
     * absence carries no cost and must not inflate the reimbursement.
     *
     * @return Collection<int, Attendance>
     */
    private function windowRecords(EmployeeDeployment $deployment): Collection
    {
        $end = $deployment->deployment_end?->toDateString() ?? now()->toDateString();

        return Attendance::query()
            ->withoutGlobalScopes()
            ->where('employee_id', $deployment->employee_id)
            ->where('project_id', $deployment->project_id)
            ->whereBetween('date', [$deployment->deployment_start->toDateString(), $end])
            ->whereIn('status', ['present', 'late', 'early_leave'])
            ->get(['id', 'hours_worked', 'total_amount', 'status']);
    }

    /**
     * Units (days or hours) the employee logged on the host project — shown in
     * the cross-charge summary alongside the amount.
     */
    public function accruedUnits(EmployeeDeployment $deployment): float
    {
        $records = $this->windowRecords($deployment);

        return match ($deployment->rate_type) {
            DeploymentRateType::Daily => (float) $records->count(),
            default => round((float) $records->sum(fn (Attendance $r) => (float) $r->hours_worked), 2),
        };
    }

    /**
     * The amount the HOST owes the HOME company — EXACT COST, no margin
     * (client decision 2026-09): the sum of the worker's FROZEN day totals for
     * the host-project days in the window, i.e. precisely what the home company
     * pays the worker for those days. Mixed day types (full / half / hourly)
     * are already reflected in each row's total_amount, so this is exact.
     */
    public function accruedAmount(EmployeeDeployment $deployment): float
    {
        return round((float) $this->windowRecords($deployment)
            ->sum(fn (Attendance $r) => (float) $r->total_amount), 2);
    }

    /**
     * Refresh the persisted cross-charge from CURRENT attendance — the live
     * accrual (client decision 2026-09, Option B): while the deployment is
     * ACTIVE the charge + host expense grow as days are logged (status
     * 'pending'); on completion they are refreshed one last time and LOCKED.
     * Only Option A produces an automated charge.
     */
    public function refreshCharge(EmployeeDeployment $deployment, bool $finalize = false): ?DeploymentCharge
    {
        if ($deployment->billing_method !== BillingMethod::OptionA) {
            return null;
        }

        // A locked (completed) charge is final money — never re-open it from a
        // later attendance edit; only an explicit finalize may touch it again.
        $current = DeploymentCharge::query()->where('employee_deployment_id', $deployment->id)->first();
        if ($current !== null && $current->status === 'locked' && ! $finalize) {
            return $current;
        }

        $units = $this->accruedUnits($deployment);
        $amount = $this->accruedAmount($deployment);
        // Display rate = exact cost ÷ units (mixed day types average out).
        $rate = $units > 0.0 ? round($amount / $units, 2) : 0.0;
        $periodEnd = $deployment->deployment_end?->toDateString() ?? now()->toDateString();
        $status = $finalize ? 'locked' : 'pending';

        return DB::transaction(function () use ($deployment, $units, $rate, $amount, $periodEnd, $status): DeploymentCharge {
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
                    'status' => $status,
                ],
            );

            $this->postHostExpense($charge, $deployment, $amount, $periodEnd);

            return $charge;
        });
    }

    /**
     * Finalize the charge when a deployment completes (locks the amount).
     */
    public function generateCharge(EmployeeDeployment $deployment): ?DeploymentCharge
    {
        return $this->refreshCharge($deployment, finalize: true);
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
            // Host-minimal (2026-09): the note names the HOME company + project,
            // NEVER the deployed worker — the host must not learn Shizukani's
            // employee identity from their own expense ledger.
            'notes' => __('ui.deployments.charge_expense_note', [
                'company' => $deployment->homeCompany->name ?? '—',
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
