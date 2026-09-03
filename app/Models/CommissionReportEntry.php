<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Screen 19 — commission per employee × project × invoice.
 *
 * `original_amount` is kept untouched next to `adjusted_amount` so an
 * adjustment always shows what it changed and why. Once finalized the entry is
 * locked (CommissionService refuses further edits) — status/finalized_* are set
 * directly, not mass-assignable.
 *
 * @property int $id
 * @property CommissionStatus $status
 * @property string $month
 * @property numeric-string $base_amount
 * @property numeric-string|null $commission_percent
 * @property numeric-string $original_amount
 * @property numeric-string|null $adjusted_amount
 * @property Carbon|null $finalized_at
 * @property Carbon|null $paid_at
 */
class CommissionReportEntry extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'commission_reports';

    /** @var list<string> */
    protected $fillable = [
        // original_amount (write-once) and adjusted_amount are set by
        // CommissionService via direct assignment — never mass-assigned — so
        // they are NOT fillable; the audit story is (original, adjusted+reason).
        'employee_id', 'project_id', 'invoice_id', 'month', 'base_amount',
        'commission_percent', 'adjustment_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommissionStatus::class,
            'finalized_at' => 'datetime',
            'paid_at' => 'date:Y-m-d',
            'base_amount' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'original_amount' => 'decimal:2',
            'adjusted_amount' => 'decimal:2',
        ];
    }

    /**
     * The amount actually owed: the adjustment when present, else the original.
     */
    public function payableAmount(): float
    {
        return (float) ($this->adjusted_amount ?? $this->original_amount);
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

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
