<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A closed month. Once locked, attendance and payroll edits for that month are
 * rejected system-wide (App\Support\PeriodLock is the single authority).
 *
 * @property int $id
 * @property int $company_id
 * @property string $month 'YYYY-MM'
 */
class LockedPeriod extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'payroll';

    /** @var list<string> */
    protected $fillable = ['month', 'locked_by', 'locked_at'];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime'];
    }
}
