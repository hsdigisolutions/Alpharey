<?php

namespace App\Models;

use App\Enums\ProjectRateType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ProjectDesignationRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's rate for one worker designation (Feature 2): client_rate is what
 * we bill, worker_rate is what we pay, at rate_type. Company-owned.
 *
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property int $designation_id
 * @property numeric-string $client_rate
 * @property numeric-string $worker_rate
 * @property ProjectRateType $rate_type
 */
class ProjectDesignationRate extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<ProjectDesignationRateFactory> */
    use HasFactory;

    public string $auditModule = 'projects';

    /** @var list<string> */
    protected $fillable = ['project_id', 'designation_id', 'client_rate', 'worker_rate', 'rate_type'];

    protected function casts(): array
    {
        return [
            'client_rate' => 'decimal:2',
            'worker_rate' => 'decimal:2',
            'rate_type' => ProjectRateType::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Designation, $this> */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }
}
