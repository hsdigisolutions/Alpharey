<?php

namespace App\Models;

use App\Enums\ProjectContactRole;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ProjectContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client-side contact for one project (supervisor / engineer / PM / other).
 * Company-owned; reached through the tenant-scoped project.
 *
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property string $name
 * @property ProjectContactRole $role
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $notes
 */
class ProjectContact extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<ProjectContactFactory> */
    use HasFactory;

    public string $auditModule = 'projects';

    /** @var list<string> */
    protected $fillable = ['project_id', 'name', 'role', 'phone', 'email', 'notes'];

    protected function casts(): array
    {
        return [
            'role' => ProjectContactRole::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
