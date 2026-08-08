<?php

namespace App\Models;

use App\Enums\WeekendRateType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An admin's offer of weekend work for one date: the project, the weekend rate,
 * and which workers are invited. Company-owned; a worker's PWA reads it (scope
 * dropped) to decide whether a weekend check-in is allowed.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $project_id
 * @property Carbon $offer_date
 * @property WeekendRateType $weekend_rate_type
 * @property numeric-string|null $weekend_rate_amount
 * @property array<int, int> $invited_employee_ids
 * @property int|null $created_by
 */
class WeekendWorkOffer extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'attendance';

    /** @var list<string> */
    protected $fillable = [
        'project_id', 'offer_date', 'weekend_rate_type', 'weekend_rate_amount', 'invited_employee_ids',
    ];

    protected function casts(): array
    {
        return [
            'offer_date' => 'date:Y-m-d',
            'weekend_rate_type' => WeekendRateType::class,
            'weekend_rate_amount' => 'decimal:2',
            'invited_employee_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Is this employee on the invited list? */
    public function invites(int $employeeId): bool
    {
        return in_array($employeeId, array_map('intval', $this->invited_employee_ids), true);
    }
}
