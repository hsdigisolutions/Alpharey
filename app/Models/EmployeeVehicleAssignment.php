<?php

namespace App\Models;

use App\Enums\VehicleAssignmentType;
use App\Models\Concerns\Auditable;
use Database\Factories\EmployeeVehicleAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The employee's side of vehicle assignment — and NOT a duplicate of
 * VehicleHistory: `vehicle_id` is nullable because type='own' records a worker
 * driving their own car for work, which has no fleet row to point at.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $vehicle_id
 * @property VehicleAssignmentType $type
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
class EmployeeVehicleAssignment extends Model
{
    use Auditable;

    /** @use HasFactory<EmployeeVehicleAssignmentFactory> */
    use HasFactory;

    public string $auditModule = 'vehicles';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'vehicle_id', 'type', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'type' => VehicleAssignmentType::class,
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    /**
     * Assignments with no end date — the ones in force today.
     *
     * @param  Builder<EmployeeVehicleAssignment>  $query
     * @return Builder<EmployeeVehicleAssignment>
     */
    public function scopeActive($query)
    {
        return $query->whereNull('effective_to');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
