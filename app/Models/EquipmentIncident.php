<?php

namespace App\Models;

use App\Enums\EquipmentReturnCondition;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A damage / loss incident (Phase E) — a worker returned kit Damaged or Lost.
 * Written only through StockMovementService (alongside the write-off movement),
 * so it is not mass-assignable from a form. Recorded for the record; the admin
 * decides any cost recovery separately (no auto-expense / deduction — Q4).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $employee_id
 * @property int|null $equipment_item_id
 * @property EquipmentReturnCondition $condition
 * @property numeric-string $quantity
 * @property Carbon $incident_date
 * @property string|null $notes
 */
class EquipmentIncident extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'inventory';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'equipment_item_id', 'employee_equipment_issue_id',
        'incident_date', 'condition', 'quantity', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'condition' => EquipmentReturnCondition::class,
            'quantity' => 'decimal:2',
            'incident_date' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<EquipmentItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'equipment_item_id');
    }
}
