<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment recorded against an invoice. The invoice's paid_amount and
 * payment_status are re-derived from these rows by InvoiceTotals — never set
 * directly from input.
 *
 * @property numeric-string $amount
 * @property Carbon $payment_date
 * @property PaymentMethod|null $payment_method
 * @property string|null $reference
 * @property string|null $receipt_path
 * @property string|null $receipt_name
 */
class Payment extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'invoices';

    /** @var list<string> */
    protected $fillable = ['invoice_id', 'amount', 'payment_date', 'payment_method', 'reference', 'notes'];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
