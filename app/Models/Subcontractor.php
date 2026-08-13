<?php

namespace App\Models;

use App\Enums\ExpenseResponsibility;
use App\Enums\SubcontractorStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A subcontractor (thaekedar) engaged on a project. Company-owned; the workers
 * they bring and the payments to them hang off this record.
 *
 * The DEAL (2026-08-13): `client_amount` is what the client pays us,
 * `agreed_budget` the fixed sum agreed with the thaekedar, and
 * `expense_responsibility` who bears the project expenses (Scenario A/B).
 * Our profit is always derived — client − budget (− our expenses in B).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $project_id
 * @property string $name
 * @property string|null $nif
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $notes
 * @property numeric-string|null $client_amount
 * @property numeric-string|null $agreed_budget
 * @property ExpenseResponsibility $expense_responsibility
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property SubcontractorStatus $status
 */
class Subcontractor extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'subcontractors';

    /** @var list<string> */
    protected $fillable = [
        'project_id', 'name', 'nif', 'phone', 'email',
        'client_amount', 'agreed_budget', 'expense_responsibility',
        'start_date', 'end_date', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubcontractorStatus::class,
            'client_amount' => 'decimal:2',
            'agreed_budget' => 'decimal:2',
            'expense_responsibility' => ExpenseResponsibility::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<SubcontractorWorker, $this>
     */
    public function workers(): HasMany
    {
        return $this->hasMany(SubcontractorWorker::class);
    }

    /**
     * @return HasMany<SubcontractorPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SubcontractorPayment::class);
    }
}
