<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\LeaveCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Screen 26 Settings — the configurable leave types behind Screen 22.
 *
 * NOT tenancy-scoped: a NULL company_id is a group-wide default (the 8 seeded
 * ones) that every company sees, and a set company_id is one company's own
 * addition. Same shape as ExpenseCategory — query through scopeForCompany().
 *
 * @property int $id
 * @property int|null $company_id
 * @property bool $is_paid
 * @property bool $active
 * @property numeric-string $default_allocation
 */
class LeaveCategory extends Model
{
    use Auditable;

    /** @use HasFactory<LeaveCategoryFactory> */
    use HasFactory;

    public string $auditModule = 'leave_management';

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'key', 'name', 'description', 'default_allocation',
        'is_paid', 'active',
    ];

    protected function casts(): array
    {
        return [
            'default_allocation' => 'decimal:1',
            'is_paid' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * The categories one company can actually use: its own plus the shared
     * defaults.
     *
     * @param  Builder<LeaveCategory>  $query
     * @return Builder<LeaveCategory>
     */
    public function scopeForCompany($query, ?int $companyId)
    {
        return $query->where(function ($q) use ($companyId): void {
            $q->whereNull('company_id');

            if ($companyId !== null) {
                $q->orWhere('company_id', $companyId);
            }
        });
    }

    /**
     * @return HasMany<Leave, $this>
     */
    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }
}
