<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\VehicleFuelRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fuel fill-up record. total_cost is stored verbatim from the form (not
 * recomputed from litres × cost_per_litre) so receipt-level rounding is
 * preserved. Company-owned, auditable.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int|null $expense_id
 * @property int $company_id
 * @property int|null $employee_id
 * @property Carbon $fuel_date
 * @property numeric-string $litres
 * @property numeric-string $cost_per_litre
 * @property numeric-string $total_cost
 * @property int|null $mileage_at_fill
 * @property string|null $payment_method
 * @property string|null $notes
 * @property int|null $created_by
 */
class VehicleFuelRecord extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<VehicleFuelRecordFactory> */
    use HasFactory;

    public string $auditModule = 'vehicles';

    /** @var list<string> */
    protected $fillable = [
        'vehicle_id', 'employee_id', 'fuel_date', 'litres',
        'cost_per_litre', 'total_cost', 'mileage_at_fill',
        'payment_method', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'fuel_date' => 'date:Y-m-d',
            'litres' => 'decimal:2',
            'cost_per_litre' => 'decimal:3',
            'total_cost' => 'decimal:2',
            'mileage_at_fill' => 'integer',
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
