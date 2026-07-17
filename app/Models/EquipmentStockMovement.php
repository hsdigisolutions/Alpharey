<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EquipmentStockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Screen 23 — the stock ledger. This is the authority; an item's
 * available_stock is a cache of it.
 *
 * `balance_after` is written by StockMovementService at the moment of the
 * movement, which is what makes the running balance reconstructable later even
 * if an item's counter is ever repaired by hand.
 *
 * @property int $id
 * @property int $company_id
 * @property int $equipment_item_id
 * @property StockMovementType $movement_type
 * @property numeric-string $quantity
 * @property numeric-string|null $balance_after
 */
class EquipmentStockMovement extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<EquipmentStockMovementFactory> */
    use HasFactory;

    public string $auditModule = 'inventory';

    /** @var list<string> */
    protected $fillable = [
        'equipment_item_id', 'movement_type', 'quantity', 'employee_id',
        'project_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'movement_type' => StockMovementType::class,
            'quantity' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<EquipmentItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'equipment_item_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
