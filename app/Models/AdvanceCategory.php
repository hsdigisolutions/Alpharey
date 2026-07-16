<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Configurable advance category (Settings). company_id is nullable: a null
 * company means a group-wide default category, so this model is deliberately
 * NOT tenancy-scoped — the payroll screen offers the company's own categories
 * plus the shared defaults.
 *
 * @property int $id
 * @property int|null $company_id
 * @property bool $active
 */
class AdvanceCategory extends Model
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
