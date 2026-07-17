<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\LeaveBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Screen 22 (Balances view) — one row per employee × category × year.
 *
 * `remaining` is computed, never stored: a second copy of a derivable figure
 * is just somewhere for the truth to drift.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property int $leave_category_id
 * @property int $year
 * @property numeric-string $allocated
 * @property numeric-string $used
 * @property numeric-string $pending
 * @property numeric-string $carried_over
 */
class LeaveBalance extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<LeaveBalanceFactory> */
    use HasFactory;

    public string $auditModule = 'leave_management';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'leave_category_id', 'year', 'allocated', 'used',
        'pending', 'carried_over',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'allocated' => 'decimal:1',
            'used' => 'decimal:1',
            'pending' => 'decimal:1',
            'carried_over' => 'decimal:1',
        ];
    }

    /**
     * Allocated + carried over, less what is spent or awaiting a decision.
     * Pending counts against the balance on purpose — two requests that each
     * fit the balance must not both be approvable when together they do not.
     */
    public function remaining(): float
    {
        return round(
            (float) $this->allocated
            + (float) $this->carried_over
            - (float) $this->used
            - (float) $this->pending,
            1
        );
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LeaveCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LeaveCategory::class, 'leave_category_id');
    }
}
