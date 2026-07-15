<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $scheduled_date
 */
class Alert extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'projects';

    /** @var list<string> */
    protected $fillable = [
        'alert_type', 'title', 'message', 'scheduled_date',
        'email_recipients', 'email_cc', 'status',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
