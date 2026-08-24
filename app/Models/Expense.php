<?php

namespace App\Models;

use App\Enums\BearableBy;
use App\Enums\ExpenseType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VatRate;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Screen 10 Gastos / Screen 09 Tab 6. Company-owned.
 *
 * An expense carrying BOTH employee_id and project_id is a "worker project
 * expense" and is pulled into that employee's payroll for the month
 * (REQUIREMENTS.md Screen 12) — see PayrollService::projectExpensesFor().
 *
 * `approved`/`approved_by`/`approved_at` and `file_path` are NOT mass
 * assignable — set them directly (same rule as Measurement + Document).
 *
 * @property int $id
 * @property int $company_id
 * @property ExpenseType $type
 * @property PaymentStatus $payment_status
 * @property PaymentMethod|null $payment_method
 * @property Carbon $date
 * @property bool $approved
 * @property bool $is_reimbursable
 * @property BearableBy $bearable_by
 * @property bool $deduct_from_salary
 * @property Carbon|null $due_date
 * @property Carbon|null $payment_date
 * @property numeric-string $subtotal
 * @property VatRate|null $vat_rate
 * @property float|null $vat_custom_percent
 * @property numeric-string $vat_amount
 * @property numeric-string $total
 * @property string|null $source
 * @property int|null $source_id
 * @property int|null $vehicle_id
 * @property string|null $vehicle_expense_type
 * @property string|null $review_status
 */
class Expense extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    public string $auditModule = 'expenses';

    /** @var list<string> */
    protected $fillable = [
        'number', 'type', 'expense_category_id', 'vendor_id', 'project_id', 'vehicle_id', 'vehicle_expense_type',
        'employee_id', 'company_card_id', 'date', 'due_date', 'subtotal',
        'vat_rate', 'vat_custom_percent', 'vat_amount', 'total', 'payment_method', 'payment_status',
        'payment_date', 'is_reimbursable', 'bearable_by', 'deduct_from_salary', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => ExpenseType::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'vat_rate' => VatRate::class,
            'vat_custom_percent' => 'float',
            'bearable_by' => BearableBy::class,
            'date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'payment_date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'approved' => 'boolean',
            'is_reimbursable' => 'boolean',
            'deduct_from_salary' => 'boolean',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<ExpenseLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(ExpenseLineItem::class)->orderBy('sort_order');
    }

    /**
     * Category slices of a multi-category expense (Smart Expense Split). Empty
     * for a single-category expense — see categoryBreakdown().
     *
     * @return HasMany<ExpenseSplit, $this>
     */
    public function splits(): HasMany
    {
        return $this->hasMany(ExpenseSplit::class)->orderBy('sort_order');
    }

    /**
     * The single source of truth for "how is this expense's money attributed to
     * categories": split rows if present, else the whole total to its single
     * category, else null (no category at all). Every category-breakdown reader
     * (project view, reports) goes through this so single + split expenses agree.
     *
     * Eager-load ['splits.category', 'category'] at the call site.
     *
     * @return list<array{category_id: int|null, category: string|null, amount: float}>|null
     */
    public function categoryBreakdown(): ?array
    {
        if ($this->splits->isNotEmpty()) {
            return $this->splits->map(fn (ExpenseSplit $s): array => [
                'category_id' => $s->expense_category_id,
                'category' => $s->category?->name,
                'amount' => (float) $s->amount,
            ])->all();
        }

        if ($this->expense_category_id !== null) {
            return [[
                'category_id' => $this->expense_category_id,
                'category' => $this->category?->name,
                'amount' => (float) $this->total,
            ]];
        }

        return null;
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The vehicle this expense relates to (fuel / fine / maintenance), when any.
     *
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
