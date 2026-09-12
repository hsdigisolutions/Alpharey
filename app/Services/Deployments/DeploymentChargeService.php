<?php

namespace App\Services\Deployments;

use App\Enums\BillingMethod;
use App\Enums\DeploymentRateType;
use App\Enums\ExpenseType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceSubType;
use App\Enums\InvoiceType;
use App\Models\Attendance;
use App\Models\DeploymentCharge;
use App\Models\EmployeeDeployment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Scopes\CompanyScope;
use App\Services\Invoices\InvoiceTotals;
use Illuminate\Support\Carbon;
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
     * One-query summary for the deployment views: days present, billable units
     * (days or hours), and the exact-cost amount. Used by both the home
     * cross-charge card and the host-minimal presence card (the caller decides
     * which fields each side may see).
     *
     * @return array{days: int, units: float, amount: float}
     */
    public function summary(EmployeeDeployment $deployment): array
    {
        $records = $this->windowRecords($deployment);
        $days = $records->count();

        return [
            'days' => $days,
            'units' => $deployment->rate_type === DeploymentRateType::Daily
                ? (float) $days
                : round((float) $records->sum(fn (Attendance $r) => (float) $r->hours_worked), 2),
            'amount' => round((float) $records->sum(fn (Attendance $r) => (float) $r->total_amount), 2),
        ];
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

        return DB::transaction(function () use ($deployment, $units, $rate, $amount, $periodEnd, $status, $finalize): DeploymentCharge {
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

            // Settlement (2026-09): on completion the charge LOCKS and becomes
            // payable — stamp invoiced_at once so the host's "payable" and the
            // home's "receivable" both date from completion. Never re-stamped,
            // and the settlement_status/paid_* columns are left untouched here
            // (they belong to the separate host "mark paid" action, which must
            // never run through this P&L-bearing engine).
            if ($finalize && $charge->invoiced_at === null) {
                $charge->invoiced_at = now();
                $charge->save();
            }

            $this->postHostExpense($charge, $deployment, $amount, $periodEnd);

            // On completion (finalize) the HOME company issues a real invoice to
            // the HOST for the deployed labour — the formal document minted from
            // this charge. Only at finalize: an accruing charge has no invoice
            // yet, and a later attendance edit returns early above (locked), so
            // the invoice is generated exactly once.
            if ($finalize) {
                $this->postHomeInvoice($charge, $deployment, $amount, $periodEnd);
            }

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

    /**
     * Backfill: mint the home invoice for an already-completed charge WITHOUT
     * recomputing the amount (the charge is locked — its stored amount is final).
     * Idempotent: postHomeInvoice keys off deployment_charge_id.
     */
    public function ensureHomeInvoice(DeploymentCharge $charge): void
    {
        $deployment = EmployeeDeployment::query()->withoutGlobalScopes()->find($charge->employee_deployment_id);
        if ($deployment === null) {
            return;
        }

        $period = $charge->period_end ?? $charge->period_start;

        $this->postHomeInvoice(
            $charge,
            $deployment,
            (float) $charge->amount,
            Carbon::parse($period)->toDateString(),
        );
    }

    /**
     * Post (or refresh) the HOME company's inter-company invoice for the charge
     * (2026-09-12). This is the formal receivable document; the host's payable
     * stays the internal_deployment Expense above. The two are the cross-module
     * mirror (WorkerExpense ↔ Expense pattern) — no invoice tenancy is broken.
     *
     * Safe by construction:
     *  - keyed off deployment_charge_id, so re-running never duplicates;
     *  - company_id = HOME (never the acting session), counterparty = HOST;
     *  - NON-TAXABLE (is_taxable=false, no VAT line) per the standing "no
     *    inter-company VAT" decision — a one-field change if the gestoría later
     *    rules VAT applies;
     *  - project_id stays NULL: the host's project is not a home project, so
     *    this never leaks into any home project's P&L (only company revenue);
     *  - the line description names the PROJECT + period, NEVER the worker, so
     *    the host-facing PDF stays anonymised.
     */
    private function postHomeInvoice(
        DeploymentCharge $charge,
        EmployeeDeployment $deployment,
        float $amount,
        string $periodEnd,
    ): void {
        $invoice = Invoice::query()->withoutGlobalScope(CompanyScope::class)
            ->where('deployment_charge_id', $charge->id)->first() ?? new Invoice;
        $isNew = ! $invoice->exists;

        $deployment->loadMissing(['project:id,name', 'hostCompany:id,name']);
        $periodStart = $deployment->deployment_start->toDateString();

        $invoice->fill([
            'type' => InvoiceType::Sale->value,
            'sub_type' => InvoiceSubType::Final->value,
            'invoice_date' => $periodEnd,
            'is_taxable' => false,
            'vat_rate' => null,
            'status' => InvoiceStatus::Sent->value,
            'notes' => __('ui.deployments.invoice_note', [
                'company' => $deployment->hostCompany->name ?? '—',
            ]),
        ]);
        $invoice->company_id = $deployment->home_company_id;      // HOME issues
        $invoice->counterparty_company_id = $deployment->host_company_id;
        $invoice->deployment_charge_id = $charge->id;
        $invoice->client_id = null;
        $invoice->project_id = null;
        if ($isNew) {
            $invoice->number = Invoice::nextDeploymentNumber($deployment->home_company_id);
        }
        $invoice->save();

        // Single worker-free line: project + period only.
        $invoice->lineItems()->delete();
        $line = new InvoiceLineItem([
            'description' => __('ui.deployments.invoice_line', [
                'project' => $deployment->project->name ?? '—',
                'start' => $periodStart,
                'end' => $periodEnd,
            ]),
            'quantity' => '1',
            'unit_price' => (string) $amount,
            'line_total' => (string) $amount,
            'sort_order' => 0,
        ]);
        $line->invoice_id = $invoice->id;
        $line->save();

        // Derives subtotal/total (no VAT) + payment_status from the (as yet
        // absent) payments — leaves it Unpaid until the host settles.
        app(InvoiceTotals::class)->apply($invoice);
    }
}
