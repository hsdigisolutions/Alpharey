<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's department (Civil Works, Electrical, …). Company-owned catalogue
 * managed in Settings; the employee form's Department field and the list filter
 * source their options from here.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property bool $active
 */
class Department extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    public string $auditModule = 'settings';

    /** @var list<string> */
    protected $fillable = ['name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** How many of the company's employees sit in this department (delete guard). */
    public function employeeCount(): int
    {
        return $this->employees()->count();
    }
}
