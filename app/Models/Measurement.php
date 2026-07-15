<?php

namespace App\Models;

use App\Enums\MeasurementType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
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
 * @property Carbon|null $approved_at
 */
class Measurement extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<MeasurementFactory> */
    use HasFactory;

    public string $auditModule = 'measurements';

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
        return $this->belongsTo(Employee::class);
    }
}
