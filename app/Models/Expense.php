<?php

namespace App\Models;

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
 * @property Carbon|null $due_date
 * @property Carbon|null $payment_date
 * @property numeric-string $subtotal
 * @property VatRate|null $vat_rate
 * @property numeric-string $vat_amount
 * @property numeric-string $total
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
        'number', 'type', 'expense_category_id', 'vendor_id', 'project_id',
        'employee_id', 'company_card_id', 'date', 'due_date', 'subtotal',
        'vat_rate', 'vat_amount', 'total', 'payment_method', 'payment_status',
        'payment_date', 'is_reimbursable', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => ExpenseType::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'vat_rate' => VatRate::class,
            'date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'payment_date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'approved' => 'boolean',
            'is_reimbursable' => 'boolean',
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
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * @return BelongsTo<CompanyCard, $this>
     */
    public function companyCard(): BelongsTo
    {
        return $this->belongsTo(CompanyCard::class, 'company_card_id');
    }
}
