<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to every company-owned model. Adds the tenancy global scope and
 * auto-fills company_id from the active company on create (CLAUDE.md
 * conventions). Shared models (clients, vendors, proposals) must NOT use
 * this trait — they are cross-company by design (REQUIREMENTS.md §2).
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', app(CurrentCompany::class)->id());
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Explicit, deliberate opt-out for system contexts (importers, scheduler).
     *
     * @return Builder<static>
     */
    public static function acrossAllCompanies(): Builder
    {
        return static::query()->withoutGlobalScope(CompanyScope::class);
    }
}
