<?php

namespace Tests\Fixtures;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only stand-in for a company-owned, audited model (an "employee"-like
 * record). Mirrors the real conventions: company_id is NOT mass assignable
 * (the BelongsToCompany creating hook fills it from the active company), and
 * sensitive attributes are hidden so the audit trail never captures them.
 */
class ScopedItem extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @var string */
    protected $table = 'scoped_items';

    /** @var list<string> */
    protected $fillable = ['name', 'secret'];

    /** @var list<string> */
    protected $hidden = ['secret'];

    public string $auditModule = 'employees';
}
