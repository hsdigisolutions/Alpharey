<?php

namespace App\Models;

use App\Enums\DeploymentRateType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
 * @property int|null $expense_id the internal expense posted on the host
 * @property string $settlement_status unpaid|paid — the host's reimbursement status
 * @property Carbon|null $invoiced_at stamped when the charge locks (completion)
 * @property Carbon|null $paid_at stamped when the host marks it paid
 * @property int|null $paid_by user who marked it paid
 */
class DeploymentCharge extends Model
{
    use Auditable;

    public string $auditModule = 'deployments';

    /**
     * settlement_status / invoiced_at / paid_at / paid_by are server-set (never
     * mass-assigned) — the settlement flow writes them directly, exactly as the
     * expense-approval columns are set directly elsewhere.
     *
     * @var list<string>
     */
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
            'invoiced_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
