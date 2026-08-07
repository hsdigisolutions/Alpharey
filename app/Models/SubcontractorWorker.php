<?php

namespace App\Models;

use App\Enums\SubcontractorPaymentStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A worker the subcontractor brought. Free-text name; when they are actually
 * one of OUR employees, `employee_id` links to the record. `total_agreed` is
 * computed (days × rate) by the service, never trusted from the client.
 *
 * @property int $id
 * @property int $subcontractor_id
 * @property string $name
 * @property bool $is_our_employee
 * @property int|null $employee_id
 * @property numeric-string $days_worked
 * @property numeric-string $agreed_rate
 * @property numeric-string $total_agreed
 * @property string|null $notes
 * @property SubcontractorPaymentStatus $payment_status
 */
class SubcontractorWorker extends Model
{
    use Auditable;

    public string $auditModule = 'subcontractors';

    /** @var list<string> */
    protected $fillable = [
        'name', 'is_our_employee', 'employee_id', 'days_worked',
        'agreed_rate', 'total_agreed', 'payment_status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_our_employee' => 'boolean',
            'days_worked' => 'decimal:2',
            'agreed_rate' => 'decimal:2',
            'total_agreed' => 'decimal:2',
            'payment_status' => SubcontractorPaymentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }
}
