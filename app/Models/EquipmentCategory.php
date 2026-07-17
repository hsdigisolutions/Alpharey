<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\EquipmentCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Screen 23 — equipment categories. Like LeaveCategory and ExpenseCategory:
 * NOT tenancy-scoped, a NULL company_id is a group-wide default.
 *
 * @property int $id
 * @property int|null $company_id
 * @property bool $active
 */
class EquipmentCategory extends Model
{
    use Auditable;

    /** @use HasFactory<EquipmentCategoryFactory> */
    use HasFactory;

    public string $auditModule = 'inventory';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'description', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @param  Builder<EquipmentCategory>  $query
     * @return Builder<EquipmentCategory>
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
     * @return HasMany<EquipmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EquipmentItem::class);
    }
}
