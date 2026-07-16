<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Expense category (Settings). Like AdvanceCategory, a null company_id means a
 * group-wide default, so this is deliberately not tenancy-scoped.
 *
 * @property int|null $company_id
 * @property bool $active
 */
class ExpenseCategory extends Model
{
    use Auditable;

    public string $auditModule = 'settings';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
