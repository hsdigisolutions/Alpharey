<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\LeaveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Screen 22 — a leave request. Company-owned.
 *
 * Keyed on employee_id, NOT user_id as the legacy schema had it: approved
 * leave writes attendance rows and therefore reaches payroll, and both of
 * those are keyed on employees (see the leave tables migration).
 *
 * `reviewed_by`/`reviewed_at`/`review_notes` and `file_path` are NOT mass
 * assignable — LeaveService sets them (same rule as Measurement + Document).
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property int $leave_category_id
 * @property LeaveStatus $status
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property numeric-string $total_days
 * @property Carbon|null $reviewed_at
 * @property string|null $file_path
 */
class Leave extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<LeaveFactory> */
    use HasFactory;

    public string $auditModule = 'leave_management';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'leave_category_id', 'start_date', 'end_date',
        'total_days', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeaveStatus::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
            'total_days' => 'decimal:1',
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
     * @return BelongsTo<LeaveCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LeaveCategory::class, 'leave_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
