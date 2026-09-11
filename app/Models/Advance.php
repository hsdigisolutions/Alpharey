<?php

namespace App\Models;

use App\Enums\AdvanceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AdvanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Salary advance (Screen 12). The amount is encrypted — it is pay data.
 * `payroll_month` links the advance to the month it is deducted from.
 *
 * @property int $id
 * @property AdvanceStatus $status
 * @property Carbon $request_date
 * @property string|null $payroll_month
 * @property string|null $payment_method
 * @property string|null $receipt_path
 * @property string|null $receipt_name
 */
class Advance extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<AdvanceFactory> */
    use HasFactory;

    public string $auditModule = 'payroll';

    /** @var list<string> */
    public array $auditExclude = ['amount'];

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'advance_category_id', 'amount', 'reason', 'status',
        'request_date', 'payment_date', 'payroll_month', 'payment_method',
    ];

    // receipt_path stays out of every payload — the file is reached only through
    // the gated + audited download route (Rule 10), never a public URL.
    /** @var list<string> */
    protected $hidden = ['amount', 'receipt_path'];

    protected function casts(): array
    {
        return [
            'status' => AdvanceStatus::class,
            'request_date' => 'date:Y-m-d',
            'payment_date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'amount' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<AdvanceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AdvanceCategory::class, 'advance_category_id');
    }
}
