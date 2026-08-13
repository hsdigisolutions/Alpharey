<?php

namespace App\Models;

use App\Enums\EquipmentItemType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EquipmentItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Screen 23 — a stock item. Company-owned: a helmet in one company's store is
 * not stock another company can issue.
 *
 * `total_stock` and `available_stock` are NOT mass assignable and are
 * maintained only by StockMovementService. equipment_stock_movements is the
 * ledger and these two are its cached tail; writing them from a form would let
 * the counter and the ledger disagree with nothing to say which is right.
 *
 * @property int $id
 * @property int $company_id
 * @property EquipmentItemType $item_type
 * @property bool $active
 * @property numeric-string $total_stock
 * @property numeric-string $available_stock
 * @property numeric-string $minimum_stock
 */
class EquipmentItem extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<EquipmentItemFactory> */
    use HasFactory;

    use SoftDeletes;

    public string $auditModule = 'inventory';

    /** @var list<string> */
    protected $fillable = [
        'equipment_category_id', 'name', 'sku', 'item_type', 'unit',
        'minimum_stock', 'active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => EquipmentItemType::class,
            'active' => 'boolean',
            'total_stock' => 'decimal:2',
            'available_stock' => 'decimal:2',
            'minimum_stock' => 'decimal:2',
        ];
    }

    /**
     * Stock has fallen to or below the reorder threshold. A minimum of 0 means
     * "not tracked", not "always low".
     */
    public function isLowStock(): bool
    {
        return (float) $this->minimum_stock > 0
            && (float) $this->available_stock <= (float) $this->minimum_stock;
    }

    /**
     * @return BelongsTo<EquipmentCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }

    /**
     * @return HasMany<EquipmentStockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(EquipmentStockMovement::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<EmployeeEquipmentIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(EmployeeEquipmentIssue::class);
    }

    /**
     * @return HasMany<EquipmentProjectAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EquipmentProjectAssignment::class);
    }
}
