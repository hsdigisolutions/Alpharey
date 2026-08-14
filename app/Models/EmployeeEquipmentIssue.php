<?php

namespace App\Models;

use App\Enums\EquipmentIssueStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EmployeeEquipmentIssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Screen 23 — kit issued to a worker, and what is still out.
 *
 * `returned_quantity`, `return_date` and `status` are NOT mass assignable —
 * the return path goes through EquipmentIssueService so the stock ledger and
 * the issue always move together.
 *
 * @property int $id
 * @property int $company_id
 * @property EquipmentIssueStatus $status
 * @property numeric-string $issued_quantity
 * @property numeric-string $returned_quantity
 * @property Carbon $issue_date
 * @property Carbon|null $expected_return_date
 * @property Carbon|null $expiry_date
 * @property Carbon|null $return_date
 */
class EmployeeEquipmentIssue extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<EmployeeEquipmentIssueFactory> */
    use HasFactory;

    public string $auditModule = 'inventory';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'equipment_item_id', 'issued_quantity', 'issue_date',
        'expected_return_date', 'expiry_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => EquipmentIssueStatus::class,
            'issued_quantity' => 'decimal:2',
            'returned_quantity' => 'decimal:2',
            'issue_date' => 'date:Y-m-d',
            'expected_return_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'return_date' => 'date:Y-m-d',
        ];
    }

    public function outstanding(): float
    {
        return round((float) $this->issued_quantity - (float) $this->returned_quantity, 2);
    }

    /** PPE expiry has passed while the kit is still out. */
    public function isExpired(): bool
    {
        return $this->expiry_date !== null
            && $this->status !== EquipmentIssueStatus::Returned
            && $this->expiry_date->isPast();
    }

    /**
     * Past its expected return with kit still out. A returned issue is never
     * overdue, however late it was.
     */
    public function isOverdue(): bool
    {
        return $this->expected_return_date !== null
            && $this->status !== EquipmentIssueStatus::Returned
            && $this->expected_return_date->isPast();
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
