<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeWageRate extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'employees';

    /** @var list<string> */
    protected $fillable = ['wage_type', 'rate', 'effective_from', 'is_default', 'notes'];

    /** @var list<string> */
    protected $hidden = ['rate'];

    protected function casts(): array
    {
        return [
            'rate' => 'encrypted',
            'effective_from' => 'date:Y-m-d',
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
}
