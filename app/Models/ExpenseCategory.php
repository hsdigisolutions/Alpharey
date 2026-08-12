<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Expense category (Settings). Like AdvanceCategory, a null company_id means a
 * group-wide default, so this is deliberately not tenancy-scoped.
 *
 * @property int|null $company_id
 * @property bool $active
 */
class ExpenseCategory extends Model
{
    use Auditable;

    public string $auditModule = 'settings';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * Categories a company may use: its own plus the group-wide defaults.
     *
     * @param  Builder<ExpenseCategory>  $query
     * @return Builder<ExpenseCategory>
     */
    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId));
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
