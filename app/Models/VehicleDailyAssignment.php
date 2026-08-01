<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\VehicleDailyAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Daily "who had this vehicle on DATE" record.
 * Unique per (vehicle_id, assigned_date) — an upsert replaces the entry for
 * that day rather than stacking multiple rows. Used by VehicleService::logFine
 * to auto-detect the driver at the time of a traffic fine.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int $company_id
 * @property int|null $employee_id
 * @property Carbon $assigned_date
 * @property string|null $notes
 * @property int|null $created_by
 */
class VehicleDailyAssignment extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<VehicleDailyAssignmentFactory> */
    use HasFactory;

    public string $auditModule = 'vehicles';

    /** @var list<string> */
    protected $fillable = [
        'vehicle_id', 'employee_id', 'assigned_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'assigned_date' => 'date:Y-m-d',
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
