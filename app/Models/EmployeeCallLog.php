<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $called_at
 * @property Carbon|null $follow_up_date
 * @property string|null $remarks
 */
class EmployeeCallLog extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'call_panel';

    /** @var list<string> */
    protected $fillable = ['called_at', 'remarks', 'follow_up_date'];

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
            'follow_up_date' => 'date:Y-m-d',
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
    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by');
    }
}
