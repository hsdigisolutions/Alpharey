<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A daily production entry against a production task (2026-08-13). The daily
 * multi-worker entry flow + completed_quantity roll-up lands in Phase D; this
 * model exists now so ProductionTask::progress() resolves.
 *
 * @property int $id
 * @property int $production_task_id
 * @property int $company_id
 * @property int|null $employee_id
 * @property Carbon $date
 * @property numeric-string $quantity
 * @property string|null $notes
 */
class TaskProgress extends Model
{
    use Auditable;
    use BelongsToCompany;

    protected $table = 'task_progress';

    public string $auditModule = 'production_tasks';

    /** @var list<string> */
    protected $fillable = [
        'production_task_id', 'employee_id', 'date', 'quantity', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'quantity' => 'decimal:2',
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
        return $this->belongsTo(Employee::class);
    }
}
