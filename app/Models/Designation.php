<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\DesignationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Worker trade type (Maestro, Peón, Electricista…). Reference data: a NULL
 * company_id is a group-wide default; a company may add its own. NOT tenancy
 * scoped (same shape as LeaveCategory / ExpenseCategory) — the `forCompany`
 * scope returns the group defaults plus that company's own.
 *
 * @property int $id
 * @property int|null $company_id
 * @property string $key
 * @property string $name
 * @property bool $active
 * @property int $sort
 */
class Designation extends Model
{
    use Auditable;

    /** @use HasFactory<DesignationFactory> */
    use HasFactory;

    public string $auditModule = 'employees';

    /** @var list<string> */
    protected $fillable = ['name', 'key', 'active', 'sort'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort' => 'integer'];
    }

    /**
     * Group-wide defaults (company_id null) plus this company's own.
     *
     * @param  Builder<Designation>  $query
     * @return Builder<Designation>
     */
    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('company_id')->orWhere('company_id', $companyId));
    }
}
