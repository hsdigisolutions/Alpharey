<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\VehicleHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Screen 21 Tab 2 — who held THIS vehicle, and when. Reached only through a
 * Vehicle (which is company-scoped), so it carries no company_id of its own.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int|null $employee_id
 * @property Carbon $assigned_from
 * @property Carbon|null $assigned_to
 */
class VehicleHistory extends Model
{
    use Auditable;

    /** @use HasFactory<VehicleHistoryFactory> */
    use HasFactory;

    protected $table = 'vehicle_history';

    public string $auditModule = 'vehicles';

    /** @var list<string> */
    protected $fillable = ['vehicle_id', 'employee_id', 'assigned_from', 'assigned_to', 'notes'];

    protected function casts(): array
    {
        return [
            'assigned_from' => 'datetime',
            'assigned_to' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
