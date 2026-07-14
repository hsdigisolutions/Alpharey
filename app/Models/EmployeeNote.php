<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $noted_at
 * @property string $type
 * @property string|null $attachment_name
 */
class EmployeeNote extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'employees';

    /** @var list<string> */
    protected $fillable = ['type', 'body', 'noted_at'];

    protected function casts(): array
    {
        return [
            'noted_at' => 'datetime',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
