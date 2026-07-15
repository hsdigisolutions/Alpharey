<?php

namespace App\Models;

use App\Enums\OvertimePolicyType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * @property OvertimePolicyType $type
 * @property numeric-string|null $rate
 */
class OvertimePolicy extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'payroll';

    /** @var list<string> */
    protected $fillable = ['name', 'type', 'rate', 'daily_threshold_hours', 'accumulate_hours_per_day', 'notes'];

    protected function casts(): array
    {
        return [
            'type' => OvertimePolicyType::class,
            'rate' => 'decimal:2',
            'daily_threshold_hours' => 'decimal:2',
            'accumulate_hours_per_day' => 'decimal:2',
        ];
    }
}
