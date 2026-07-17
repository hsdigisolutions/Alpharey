<?php

namespace App\Models;

use App\Enums\FuelType;
use App\Enums\VehicleOwnership;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Screen 21 — a fleet vehicle. Company-owned.
 *
 * The two expiry dates are compliance data, not notes: DocumentStatus grades
 * them on the same traffic light as employee documents and verto:scan-documents
 * alerts on them. See VehicleCompliance.
 *
 * @property int $id
 * @property int $company_id
 * @property string $plate_number
 * @property VehicleOwnership $ownership
 * @property FuelType|null $fuel_type
 * @property bool $active
 * @property Carbon|null $insurance_expiry_date
 * @property Carbon|null $ita_expiry_date
 * @property Carbon|null $purchase_date
 * @property Carbon|null $last_oil_change_date
 * @property Carbon|null $next_service_date
 * @property Carbon|null $last_tyre_change_date
 * @property int|null $current_mileage
 * @property int|null $last_oil_change_mileage
 * @property int|null $oil_change_interval_km
 * @property numeric-string $maintenance_cost_total
 */
class Vehicle extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    public string $auditModule = 'vehicles';

    /** @var list<string> */
    protected $fillable = [
        'plate_number', 'brand', 'model', 'year', 'ownership',
        'assigned_employee_id', 'active', 'fuel_type', 'color', 'vin_number',
        'insurance_policy_number', 'insurance_expiry_date', 'ita_expiry_date',
        'purchase_date', 'current_mileage', 'last_oil_change_mileage',
        'last_oil_change_date', 'oil_change_interval_km', 'next_service_date',
        'last_tyre_change_date', 'last_tyre_change_mileage',
        'maintenance_notes', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'ownership' => VehicleOwnership::class,
            'fuel_type' => FuelType::class,
            'active' => 'boolean',
            'year' => 'integer',
            'insurance_expiry_date' => 'date:Y-m-d',
            'ita_expiry_date' => 'date:Y-m-d',
            'purchase_date' => 'date:Y-m-d',
            'last_oil_change_date' => 'date:Y-m-d',
            'next_service_date' => 'date:Y-m-d',
            'last_tyre_change_date' => 'date:Y-m-d',
            'current_mileage' => 'integer',
            'last_oil_change_mileage' => 'integer',
            'oil_change_interval_km' => 'integer',
            'last_tyre_change_mileage' => 'integer',
            'maintenance_cost_total' => 'decimal:2',
        ];
    }

    /**
     * The odometer reading at which the next oil change is due, or null when
     * the vehicle has no interval configured.
     */
    public function oilChangeDueAt(): ?int
    {
        if ($this->oil_change_interval_km === null || $this->last_oil_change_mileage === null) {
            return null;
        }

        return $this->last_oil_change_mileage + $this->oil_change_interval_km;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    /**
     * @return HasMany<VehicleHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(VehicleHistory::class)->orderByDesc('assigned_from');
    }

    /**
     * @return HasMany<VehicleMaintenanceHistory, $this>
     */
    public function maintenanceHistory(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceHistory::class)->orderByDesc('maintenance_date');
    }

    /**
     * @return HasMany<VehicleMileageHistory, $this>
     */
    public function mileageHistory(): HasMany
    {
        return $this->hasMany(VehicleMileageHistory::class)->orderByDesc('recorded_at');
    }
}
