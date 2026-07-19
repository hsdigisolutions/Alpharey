<?php

namespace App\Services\Companies;

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Project;

/**
 * Safety checks before a company can be removed (REQUIREMENTS.md §2:
 * "serious action — must require confirmation step and safety checks").
 *
 * Removal is a Super Admin action, so these queries opt OUT of the tenant
 * scope (`withoutGlobalScopes`) and match the target company by id directly —
 * the acting company in the session is unrelated to the one being removed.
 *
 * Every blocker exists to stop live history being orphaned: users, workforce,
 * project history, and money still owed. Soft-deleted employees do not count
 * (their company is being closed anyway), but ACTIVE ones do.
 */
class CompanyRemovalGuard
{
    /**
     * Translation keys of everything blocking removal; empty = safe.
     *
     * @return list<string>
     */
    public function blockers(Company $company): array
    {
        $blockers = [];

        if ($company->users()->count() > 0) {
            $blockers[] = 'companies.blocked_users';
        }

        if (Employee::query()->withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            $blockers[] = 'companies.blocked_employees';
        }

        if (Project::query()->withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            $blockers[] = 'companies.blocked_projects';
        }

        // Money still owed to or by the company — unpaid/partial invoices.
        $hasOpenInvoices = Invoice::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('payment_status', [
                PaymentStatus::Unpaid->value,
                PaymentStatus::Partial->value,
                PaymentStatus::Pending->value,
            ])
            ->exists();

        if ($hasOpenInvoices) {
            $blockers[] = 'companies.blocked_invoices';
        }

        return $blockers;
    }
}
