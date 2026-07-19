<?php

namespace App\Services\Commissions;

use App\Enums\CommissionStatus;
use App\Enums\InvoiceType;
use App\Models\CommissionReportEntry;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\ProjectEmployeeRate;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Screen 19 — commission per employee × project × invoice.
 *
 * Base: the SALE invoice total for a project, times the employee's
 * commission_percent. Only employees actually assigned to that project
 * (project_employee_rates) earn on it.
 *
 * ASSUMPTION FLAGGED TO THE CLIENT: commission accrues on the invoice TOTAL,
 * not on the amount collected so far. The screen shows "Amount Paid" alongside
 * so a clerk can hold back a commission on an unpaid invoice, but nothing
 * enforces that. If they want commission only on collected money, this is the
 * one line to change (see baseFor()).
 *
 * `original_amount` is never overwritten by an adjustment — the pair
 * (original, adjusted + reason) is the audit story.
 */
class CommissionService
{
    /**
     * Generate (or refresh) the month's entries for a company.
     * Finalized/paid entries are left alone — they are settled.
     *
     * @return int entries written
     */
    public function generateMonth(int $companyId, string $month): int
    {
        [$start, $end] = $this->bounds($month);

        $invoices = Invoice::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('type', InvoiceType::Sale->value)
            ->whereNotNull('project_id')
            ->whereBetween('invoice_date', [$start, $end])
            ->get();

        $written = 0;

        DB::transaction(function () use ($invoices, $companyId, $month, &$written): void {
            foreach ($invoices as $invoice) {
                $earnerIds = ProjectEmployeeRate::query()->withoutGlobalScopes()
                    ->where('project_id', $invoice->project_id)
                    ->pluck('employee_id');

                // Read the employees WITHOUT the tenant scope and keyed by id.
                // Eager-loading them through the relation would re-apply
                // Employee's global scope, which returns nothing when this runs
                // outside a request (a scheduled generate, console, a test) —
                // and every earner would be silently skipped.
                // Tenant scope only — keeping SoftDeletes means an employee
                // removed from the company stops earning NEW commission
                // entries (existing entries are history and stay).
                $employees = Employee::query()->withoutGlobalScope(CompanyScope::class)
                    ->whereIn('id', $earnerIds)
                    ->get(['id', 'commission_percent'])
                    ->keyBy('id');

                foreach ($earnerIds as $employeeId) {
                    $percent = (float) ($employees->get($employeeId)?->getAttribute('commission_percent') ?? 0);

                    if ($percent <= 0) {
                        continue; // this worker does not earn commission
                    }

                    $existing = CommissionReportEntry::query()->withoutGlobalScopes()
                        ->where('employee_id', $employeeId)
                        ->where('invoice_id', $invoice->id)
                        ->first();

                    // Never rewrite money that has been settled.
                    if ($existing !== null && $existing->status !== CommissionStatus::Draft) {
                        continue;
                    }

                    $base = $this->baseFor($invoice);

                    $entry = $existing ?? new CommissionReportEntry;
                    $entry->company_id = $companyId;
                    $entry->employee_id = $employeeId;
                    $entry->project_id = $invoice->project_id;
                    $entry->invoice_id = $invoice->id;
                    $entry->month = $month;
                    $entry->base_amount = (string) $base;
                    $entry->commission_percent = (string) $percent;
                    $entry->original_amount = (string) round($base * $percent / 100, 2);
                    $entry->save();

                    $written++;
                }
            }
        });

        return $written;
    }

    /**
     * The amount commission is calculated on. Invoice total today — swap for
     * `paid_amount` if the client wants commission only on collected money.
     */
    private function baseFor(Invoice $invoice): float
    {
        return (float) $invoice->total;
    }

    /**
     * Adjust an entry: keep the original, record the new amount + why.
     */
    public function adjust(CommissionReportEntry $entry, float $amount, string $reason): CommissionReportEntry
    {
        $this->assertEditable($entry);

        $entry->adjusted_amount = (string) round($amount, 2);
        $entry->adjustment_reason = $reason;
        $entry->save();

        return $entry;
    }

    /**
     * Finalize — locks the entry (the UI confirms first, per the spec).
     */
    public function finalize(CommissionReportEntry $entry): CommissionReportEntry
    {
        $this->assertEditable($entry);

        $entry->status = CommissionStatus::Finalized;
        $entry->finalized_at = now();
        $entry->finalized_by = Auth::id();
        $entry->save();

        return $entry;
    }

    public function markPaid(CommissionReportEntry $entry): CommissionReportEntry
    {
        if ($entry->status === CommissionStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('ui.commissions.finalize_first'),
            ]);
        }

        $entry->status = CommissionStatus::Paid;
        $entry->paid_at = now();
        $entry->save();

        return $entry;
    }

    /**
     * A finalized entry is locked: that is the whole point of finalizing.
     */
    private function assertEditable(CommissionReportEntry $entry): void
    {
        if ($entry->status !== CommissionStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('ui.commissions.locked'),
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function bounds(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }
}
