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

        $builder->where($model->qualifyColumn('company_id'), $user->company_id);
    }
}
