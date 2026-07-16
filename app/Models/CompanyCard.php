<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company payment card, offered as a payment method on expenses (Settings).
 * Only the last four digits are stored — never a full PAN (SECURITY.md).
 *
 * @property int $company_id
 * @property bool $active
 */
class CompanyCard extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'settings';

    /** @var list<string> */
    protected $fillable = ['label', 'last_four', 'holder_employee_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'holder_employee_id');
    }
}
