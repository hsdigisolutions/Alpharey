<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-project wage override for an own-company worker (Screen 09 Tab 2).
 * project_rate is wage-sensitive → encrypted + hidden.
 */
class ProjectEmployeeRate extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'projects';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'wage_type', 'project_rate'];

    /** @var list<string> */
    protected $hidden = ['project_rate'];

    protected function casts(): array
    {
        return [
            'project_rate' => 'encrypted',
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
