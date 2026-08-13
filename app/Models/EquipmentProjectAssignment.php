<?php

namespace App\Models;

use App\Enums\EquipmentAssignmentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EquipmentProjectAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Screen 23 — kit assigned to a site rather than to a person.
 *
 * `returned_quantity` and `status` are NOT mass assignable — the return path
 * goes through StockMovementService so the ledger and the assignment always
 * move together (mirrors EmployeeEquipmentIssue).
 *
 * @property int $id
 * @property int $company_id
 * @property EquipmentAssignmentStatus $status
 * @property numeric-string $quantity
 * @property numeric-string $returned_quantity
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 */
class EquipmentProjectAssignment extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<EquipmentProjectAssignmentFactory> */
    use HasFactory;

    public string $auditModule = 'inventory';

    /** @var list<string> */
    protected $fillable = [
        'equipment_item_id', 'project_id', 'quantity', 'start_date',
        'end_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => EquipmentAssignmentStatus::class,
            'quantity' => 'decimal:2',
            'returned_quantity' => 'decimal:2',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    /** Still out on site — the assigned quantity not yet returned. */
    public function outstanding(): float
    {
        return round((float) $this->quantity - (float) $this->returned_quantity, 2);
    }

    /**
     * @return BelongsTo<EquipmentItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'equipment_item_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
