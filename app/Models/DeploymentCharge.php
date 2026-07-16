<?php

namespace App\Models;

use App\Enums\DeploymentRateType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Option A cross-charge: what the host company owes the home company for a
 * deployment (PAYROLL_DEPLOYMENTS.md). No tenancy scope — it belongs to two
 * companies; visibility follows the parent deployment.
 *
 * @property int $id
 * @property DeploymentRateType $rate_type
 * @property numeric-string $units
 * @property numeric-string $rate
 * @property numeric-string $amount
 */
class DeploymentCharge extends Model
{
    use Auditable;

    public string $auditModule = 'deployments';

    /** @var list<string> */
    protected $fillable = [
        'employee_deployment_id', 'home_company_id', 'host_company_id', 'project_id',
        'period_start', 'period_end', 'units', 'rate_type', 'rate', 'amount', 'status',
    ];

    protected function casts(): array
    {
        return [
            'rate_type' => DeploymentRateType::class,
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'units' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<EmployeeDeployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeployment::class, 'employee_deployment_id');
    }
}
