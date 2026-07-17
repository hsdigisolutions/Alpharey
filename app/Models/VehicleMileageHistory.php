<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\VehicleMileageHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Screen 21 Tab 4 — odometer log.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int $mileage_value
 * @property Carbon $recorded_at
 */
class VehicleMileageHistory extends Model
{
    use Auditable;

    /** @use HasFactory<VehicleMileageHistoryFactory> */
    use HasFactory;

    public string $auditModule = 'vehicles';

    /** @var list<string> */
    protected $fillable = ['vehicle_id', 'mileage_value', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'mileage_value' => 'integer',
            'recorded_at' => 'date:Y-m-d',
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
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
