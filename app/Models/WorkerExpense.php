<?php

namespace App\Models;

use App\Enums\WorkerExpenseStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WorkerExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Expense submitted by a worker through the PWA at (or after) check-out.
 * The admin approves or rejects it; approved expenses are included in the
 * worker's payroll for the month.
 *
 * receipt_path is NOT mass assignable — set by the controller after the file
 * is stored (same pattern as Document + AttendanceVoiceNote).
 *
 * @property int $id
 * @property int $employee_id
 * @property int $company_id
 * @property int|null $attendance_id
 * @property int|null $project_id
 * @property int|null $vehicle_id
 * @property int|null $auto_expense_id
 * @property Carbon $date
 * @property numeric-string $amount
 * @property string $category
 * @property string $description
 * @property string|null $receipt_path
 * @property WorkerExpenseStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejection_reason
 * @property int|null $payroll_id
 */
class WorkerExpense extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<WorkerExpenseFactory> */
    use HasFactory;

    public string $auditModule = 'expenses';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'attendance_id', 'project_id',
        'date', 'amount', 'category', 'description',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'status' => WorkerExpenseStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Attendance, $this> */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The company expense auto-created when a fuel expense is approved. */
    /** @return BelongsTo<Expense, $this> */
    public function autoExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'auto_expense_id');
    }
}
