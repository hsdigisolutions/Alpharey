<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One category slice of a multi-category expense. amount + expense_id are NOT
 * mass assignable (set directly by ExpenseController inside the same
 * transaction as the parent expense); the amounts are VAT-inclusive and always
 * sum to the expense total.
 *
 * @property int $id
 * @property int $expense_id
 * @property int|null $expense_category_id
 * @property numeric-string $amount
 * @property string|null $description
 * @property int $sort_order
 */
class ExpenseSplit extends Model
{
    use Auditable;

    public string $auditModule = 'expenses';

    /** @var list<string> */
    protected $fillable = ['expense_category_id', 'description', 'sort_order'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
