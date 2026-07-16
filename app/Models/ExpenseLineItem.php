<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $unit_price
 * @property numeric-string $line_total
 */
class ExpenseLineItem extends Model
{
    use Auditable;

    public string $auditModule = 'expenses';

    /** @var list<string> */
    protected $fillable = ['description', 'quantity', 'unit_price', 'line_total', 'sort_order'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
