<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\VehicleFineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Traffic fine attached to a plate. The driver is auto-resolved from
 * vehicle_daily_assignments for fine_date (VehicleService::logFine) but can
 * be overridden. When charged_to=company an Expense row is auto-created and
 * stored in expense_id. file_path, paid, paid_at, expense_id are NOT mass
 * assignable — set them directly.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int $company_id
 * @property int|null $employee_id
 * @property Carbon $fine_date
 * @property numeric-string $amount
 * @property string $description
 * @property string|null $authority
 * @property string|null $file_path
 * @property string $charged_to
 * @property bool $deduct_from_salary
 * @property string|null $deduction_month
 * @property bool $paid
 * @property Carbon|null $paid_at
 * @property int|null $expense_id
 * @property int|null $created_by
 */
class VehicleFine extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<VehicleFineFactory> */
    use HasFactory;

    public string $auditModule = 'vehicles';

    /** @var list<string> */
    protected $fillable = [
        'vehicle_id', 'employee_id', 'fine_date', 'amount',
        'description', 'authority', 'charged_to',
    ];

    protected function casts(): array
    {
        return [
            'fine_date' => 'date:Y-m-d',
            'paid_at' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'paid' => 'boolean',
            'deduct_from_salary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
