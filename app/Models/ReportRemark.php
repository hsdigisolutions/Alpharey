<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Immutable project note / communication (Screen 09 Tab 8). Per the spec
 * "notes cannot be deleted once saved" — this model blocks update AND
 * delete at the model layer (like AuditLog). Append-only: no updated_at.
 *
 * @property Carbon $noted_at
 */
class ReportRemark extends Model
{
    use Auditable;
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public string $auditModule = 'projects';

    /** @var list<string> */
    protected $fillable = ['type', 'body', 'noted_at', 'attachment_name'];

    protected function casts(): array
    {
        return [
            'noted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Project notes are immutable once saved.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Project notes cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
