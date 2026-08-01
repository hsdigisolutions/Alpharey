<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use Database\Factories\VehicleSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A worker taking a company vehicle. The session is open (returned_at = null)
 * while the vehicle is in use; returning it closes the session and restores
 * vehicles.is_available = true.
 *
 * ending_mileage / ending_fuel_level / return_notes are set on return (not mass
 * assignable — filled by VehicleSessionService::returnVehicle). km_driven is
 * computed and stored on return so queries don't have to subtract.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int $employee_id
 * @property int $company_id
 * @property Carbon $taken_at
 * @property Carbon|null $returned_at
 * @property int $starting_mileage
 * @property int|null $ending_mileage
 * @property int|null $km_driven
 * @property int|null $starting_fuel_level
 * @property int|null $ending_fuel_level
 * @property numeric-string|null $fuel_added_litres
 * @property string|null $return_notes
 * @property bool $overdue_alerted
 */
class VehicleSession extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<VehicleSessionFactory> */
    use HasFactory;

    public string $auditModule = 'vehicles';

    /** @var list<string> */
    protected $fillable = [
        'vehicle_id', 'employee_id', 'taken_at',
        'starting_mileage', 'starting_fuel_level',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'returned_at' => 'datetime',
            'starting_mileage' => 'integer',
            'ending_mileage' => 'integer',
            'km_driven' => 'integer',
            'starting_fuel_level' => 'integer',
            'ending_fuel_level' => 'integer',
            'fuel_added_litres' => 'decimal:2',
            'overdue_alerted' => 'boolean',
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

    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }

    /**
     * Route model binding without CompanyScope: workers have no CRM session so
     * the scope would return 0 rows. Ownership is validated in the controller.
     *
     * @param  mixed  $value
     */
    public function resolveRouteBinding($value, $field = null): ?static
    {
        return static::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
