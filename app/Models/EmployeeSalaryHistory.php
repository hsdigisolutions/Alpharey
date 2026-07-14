<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only salary change history (one row per changed wage field).
 * Values are encrypted like their source columns.
 */
class EmployeeSalaryHistory extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'employee_salary_history';

    /** @var list<string> */
    protected $fillable = ['field', 'old_value', 'new_value'];

    /** @var list<string> */
    protected $hidden = ['old_value', 'new_value'];

    protected function casts(): array
    {
        return [
            'old_value' => 'encrypted',
            'new_value' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
