<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use Database\Factories\TaskProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A daily production entry against a production task (2026-08-13). A "Log work"
 * action splits a total quantity across the workers present that day, writing
 * one row per worker sharing a `batch_id`. `photo_path`/`photo_name`/`logged_by`
 * are server-set (NOT fillable). The task's `completed_quantity` is recomputed
 * = Σ quantity by TaskProgressService — this is the only writer of that column.
 *
 * @property int $id
 * @property int $production_task_id
 * @property string|null $batch_id
 * @property int $company_id
 * @property int|null $employee_id
 * @property int|null $logged_by
 * @property Carbon $date
 * @property numeric-string $quantity
 * @property bool $is_rework
 * @property string|null $notes
 * @property string|null $photo_path
 * @property string|null $photo_name
 */
class TaskProgress extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<TaskProgressFactory> */
    use HasFactory;

    protected $table = 'task_progress';

    public string $auditModule = 'production_tasks';

    /**
     * photo_path/photo_name/batch_id/logged_by are set by the service, never
     * from client input (same discipline as attendance selfies).
     *
     * @var list<string>
     */
    protected $fillable = [
        'production_task_id', 'employee_id', 'date', 'quantity', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'quantity' => 'decimal:2',
            'is_rework' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ProductionTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProductionTask::class, 'production_task_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        // Tenant scope dropped so a transferred-away employee still resolves on
        // this company's daily-production history (their name must not vanish).
        return $this->belongsTo(Employee::class)->withoutGlobalScope(CompanyScope::class);
    }
}
