<?php

namespace App\Models;

use App\Enums\WageType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One effective-dated wage rate for an employee. The history is the sequence
 * of these rows: each owns a closed [effective_from, effective_to] range, and
 * exactly one row per employee is open (effective_to = null) — the rate in
 * force today. AttendanceService freezes the correct rate for each worked day
 * from this table; a later change never rewrites an earlier day.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $company_id
 * @property WageType|null $wage_type
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property bool $is_default
 * @property string|null $reason
 * @property int|null $created_by
 */
class EmployeeWageRate extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'employees';

    /** @var list<string> */
    protected $fillable = ['wage_type', 'rate', 'effective_from', 'effective_to', 'is_default', 'reason', 'notes'];

    /** Rate is pay data — never serialise it, never write it to the audit trail. */

    /** @var list<string> */
    protected $hidden = ['rate'];

    protected function casts(): array
    {
        return [
            'rate' => 'encrypted',
            'wage_type' => WageType::class,
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
