<?php

namespace App\Models;

use App\Enums\MeasurementStatus;
use App\Enums\MeasurementType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use Database\Factories\MeasurementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Screen 24 — Measurements. Company-owned. Approved rows feed project
 * billing (Phase 6).
 *
 * @property int $id
 * @property Carbon $date
 * @property MeasurementType $measurement_type
 * @property bool $approved
 * @property MeasurementStatus $status
 * @property string|null $rejection_reason
 * @property Carbon|null $approved_at
 */
class Measurement extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<MeasurementFactory> */
    use HasFactory;

    public string $auditModule = 'measurements';

    /**
     * Keep the legacy `approved` boolean and the `status` authority in sync on
     * every save. If only the boolean was set (old fixtures / imports), lift it
     * into status; then always mirror status → approved so no reader diverges.
     */
    protected static function booted(): void
    {
        static::saving(function (self $measurement): void {
            if ($measurement->isDirty('approved') && ! $measurement->isDirty('status')) {
                $measurement->status = $measurement->approved
                    ? MeasurementStatus::Approved
                    : MeasurementStatus::Pending;
            }

            $measurement->status ??= MeasurementStatus::Pending;
            $measurement->approved = $measurement->status === MeasurementStatus::Approved;
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'project_id', 'employee_id', 'date', 'quantity', 'unit',
        'measurement_type', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'quantity' => 'decimal:2',
            'measurement_type' => MeasurementType::class,
            'approved' => 'boolean',
            'status' => MeasurementStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        // Tenant scope dropped so a transferred-away employee still resolves on
        // this company's project measurement history (their name must not vanish).
        return $this->belongsTo(Employee::class)->withoutGlobalScope(CompanyScope::class);
    }
}
