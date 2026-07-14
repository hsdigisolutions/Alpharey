<?php

namespace App\Services\Companies;

use App\Models\Company;

/**
 * Safety checks before a company can be removed (REQUIREMENTS.md §2:
 * "serious action — must require confirmation step and safety checks").
 * Later phases append their own blockers here as their tables land
 * (employees, projects, unpaid invoices…).
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

        // Phase 2+: active employees · Phase 3+: projects · Phase 6+: unpaid invoices

        return $blockers;
    }
}
