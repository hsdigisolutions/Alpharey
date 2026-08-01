<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\VehicleMaintenanceHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Screen 21 Tab 3 — maintenance log. `cost` rolls up into the vehicle's
 * maintenance_cost_total via VehicleService, which is why it is not editable
 * directly on the vehicle.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property Carbon $maintenance_date
 * @property int|null $vehicle_km
 * @property numeric-string|null $cost
 */
class VehicleMaintenanceHistory extends Model
{
    use Auditable;

    /** @use HasFactory<VehicleMaintenanceHistoryFactory> */
    use HasFactory;

    public string $auditModule = 'vehicles';

    /** @var list<string> */
    protected $fillable = [
        'vehicle_id', 'maintenance_type', 'maintenance_date', 'vehicle_km',
        'description', 'vendor_name', 'tyre_position', 'cost',
    ];

    protected function casts(): array
    {
        return [
            'maintenance_date' => 'date:Y-m-d',
            'vehicle_km' => 'integer',
            'cost' => 'decimal:2',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
