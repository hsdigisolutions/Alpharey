<?php

namespace App\Models\Scopes;

use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global tenancy scope (SECURITY.md §2). Isolation is the DEFAULT:
 *
 *  - guest / no auth        → zero rows (whereRaw 1=0)
 *  - regular user / admin   → rows of their own company only
 *  - Super Admin, selected  → rows of the selected company
 *  - Super Admin, browsing  → unscoped (cross-company by design)
 *
 * System code (importers, scheduled jobs) that legitimately needs to cross
 * companies must opt out EXPLICITLY via Model::withoutGlobalScope(CompanyScope::class).
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            $builder->whereRaw('1 = 0');

            return;
        }

        if ($user->isSuperAdmin()) {
            $selected = app(CurrentCompany::class)->id();

            if ($selected !== null) {
                $builder->where($model->qualifyColumn('company_id'), $selected);
            }

            return;
        }

        // The ACTIVE company, not blindly the primary: a multi-company
        // Admin/Manager who switched acts in the switched company, and
        // CurrentCompany validates that selection against the user_company
        // pivot on every request. Gates (ModulePermissions) resolve through
        // the same source — data scope and permission scope cannot diverge.
        $builder->where($model->qualifyColumn('company_id'), app(CurrentCompany::class)->id());
    }
}
