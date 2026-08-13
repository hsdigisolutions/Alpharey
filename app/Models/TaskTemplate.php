<?php

namespace App\Models;

use App\Enums\ProductionTaskCategory;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\TaskTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable production-task definition (Phase C, 2026-08-13). Company-scoped
 * (`BelongsToCompany`) — templates are NOT group defaults; each company keeps
 * its own catalogue. Selecting one prefills a row in the project bulk-add grid;
 * it carries the same shape a task needs (`unit_price` is an INTERNAL cost, not
 * client billing). Nothing links a task back to a template — it is a one-shot
 * prefill, so editing a template never rewrites live tasks.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property ProductionTaskCategory $category
 * @property string|null $unit
 * @property numeric-string|null $unit_price
 * @property numeric-string|null $planned_quantity
 * @property numeric-string|null $weightage
 * @property string|null $description
 * @property bool $active
 */
class TaskTemplate extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<TaskTemplateFactory> */
    use HasFactory;

    public string $auditModule = 'production_tasks';

    /** @var list<string> */
    protected $fillable = [
        'name', 'category', 'unit', 'unit_price', 'planned_quantity',
        'weightage', 'description', 'active',
    ];

    protected function casts(): array
    {
        return [
            'category' => ProductionTaskCategory::class,
            'unit_price' => 'decimal:2',
            'planned_quantity' => 'decimal:2',
            'weightage' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<TaskTemplate>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
