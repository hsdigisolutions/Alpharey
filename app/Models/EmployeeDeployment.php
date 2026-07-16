<?php

namespace App\Models;

use App\Enums\BillingMethod;
use App\Enums\DeploymentRateType;
use App\Enums\DeploymentStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\EmployeeDeploymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * REQUIREMENTS.md §3 — cross-company deployment. Spans TWO companies, so it
 * deliberately does NOT use BelongsToCompany. Visibility is scoped in-model
 * to "home OR host company (or Super Admin)" via visibleTo(). Creation and
 * mutation are gated by the deployments.* module permissions + explicit
 * host-company ownership checks in the controller.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $home_company_id
 * @property int $host_company_id
 * @property int $project_id
 * @property DeploymentStatus $status
 * @property BillingMethod $billing_method
 * @property DeploymentRateType $rate_type
 * @property Carbon $deployment_start
 * @property Carbon|null $deployment_end
 * @property numeric-string|null $rate_during_deployment
 * @property numeric-string $split_pct
 */
class EmployeeDeployment extends Model
{
    use Auditable;

    /** @use HasFactory<EmployeeDeploymentFactory> */
    use HasFactory;

    public string $auditModule = 'deployments';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'home_company_id', 'host_company_id', 'project_id',
        'deployment_start', 'deployment_end', 'billing_method',
        'rate_during_deployment', 'rate_type', 'split_pct', 'approved_by',
        'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'billing_method' => BillingMethod::class,
            'rate_type' => DeploymentRateType::class,
            'deployment_start' => 'date:Y-m-d',
            'deployment_end' => 'date:Y-m-d',
            'rate_during_deployment' => 'decimal:2',
            'split_pct' => 'decimal:2',
        ];
    }

    /**
     * Restrict a query to deployments the user may see: their company as
     * home OR host. Super Admin (null companyId) sees all.
     *
     * @param  Builder<EmployeeDeployment>  $query
     * @return Builder<EmployeeDeployment>
     */
    public function scopeVisibleTo(Builder $query, ?int $companyId): Builder
    {
        if ($companyId === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('home_company_id', $companyId)
            ->orWhere('host_company_id', $companyId));
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        // Employee is company-scoped; deployments cross companies, so read
        // the employee without the tenant scope for display.
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function homeCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'home_company_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function hostCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'host_company_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<DeploymentCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(DeploymentCharge::class);
    }
}
