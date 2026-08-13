<?php

namespace App\Models;

use App\Enums\ProductionTaskCategory;
use App\Enums\ProductionTaskStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ProductionTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A production task on a project — internal planned-vs-actual tracking
 * (2026-08-13). `unit_price` is an INTERNAL cost rate, NOT client billing (only
 * approved measurements bill the client). `completed_quantity` is the summed
 * daily production (task_progress, Phase D); `weightage` is an advisory weight
 * for the project's overall progress.
 *
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property string $name
 * @property ProductionTaskCategory $category
 * @property string|null $house_number
 * @property string|null $unit
 * @property numeric-string $unit_price
 * @property numeric-string $planned_quantity
 * @property numeric-string $completed_quantity
 * @property numeric-string $weightage
 * @property ProductionTaskStatus $status
 * @property string|null $notes
 */
class ProductionTask extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<ProductionTaskFactory> */
    use HasFactory;

    public string $auditModule = 'production_tasks';

    /** @var list<string> */
    protected $fillable = [
        'project_id', 'name', 'category', 'house_number', 'unit',
        'unit_price', 'planned_quantity', 'weightage', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'category' => ProductionTaskCategory::class,
            'status' => ProductionTaskStatus::class,
            'unit_price' => 'decimal:2',
            'planned_quantity' => 'decimal:2',
            'completed_quantity' => 'decimal:2',
            'weightage' => 'decimal:2',
        ];
    }

    /**
     * Completion percentage (0–100, capped). 0 planned reads as 0 %.
     */
    public function progressPercent(): float
    {
        $planned = (float) $this->planned_quantity;

        return $planned > 0
            ? min(100.0, round((float) $this->completed_quantity / $planned * 100, 1))
            : 0.0;
    }

    /**
     * The completion traffic light: green ≥90 % · amber ≥50 % · red below.
     */
    public function health(): string
    {
        $pct = $this->progressPercent();

        return match (true) {
            $pct >= 90.0 => 'ok',
            $pct >= 50.0 => 'warn',
            default => 'danger',
        };
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<TaskProgress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(TaskProgress::class);
    }
}
