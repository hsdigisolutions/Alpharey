<?php

namespace App\Models;

use App\Enums\SubcontractorPaymentStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One scheduled payment to a subcontractor (anticipo, liquidación, …). Marking
 * it paid creates a linked Expense (`expense_id`) on the subcontractor's company.
 *
 * @property int $id
 * @property int $subcontractor_id
 * @property int $payment_number
 * @property Carbon|null $payment_date
 * @property numeric-string $amount
 * @property string|null $notes
 * @property SubcontractorPaymentStatus $status
 * @property int|null $expense_id
 */
class SubcontractorPayment extends Model
{
    use Auditable;

    public string $auditModule = 'subcontractors';

    /** @var list<string> */
    protected $fillable = [
        'payment_number', 'payment_date', 'amount', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'status' => SubcontractorPaymentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class);
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
