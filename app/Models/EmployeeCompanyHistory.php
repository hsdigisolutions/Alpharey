<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One COMPANY STINT of an employee (Change 2 single-record model). A transfer
 * flips the single employee record's company_id in place and records the move
 * here: the current stint is closed (ended_at = transfer date) and a new one is
 * opened (started_at = transfer date, ended_at = null).
 *
 * Spans two companies over time, so — like EmployeeDeployment — it deliberately
 * does NOT use BelongsToCompany. It is reached only through a specific employee
 * (already tenant-checked) or a Super Admin, and read-only in the UI.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $company_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
class EmployeeCompanyHistory extends Model
{
    protected $table = 'employee_company_history';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'company_id', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'date:Y-m-d',
            'ended_at' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The current, still-open stint has no end date. */
    public function isCurrent(): bool
    {
        return $this->ended_at === null;
    }
}
