<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Enums\WageType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Screen 12 — one payroll row per employee per month, computed by
 * PayrollService from the wage snapshots frozen on attendance.
 *
 * Every money column is encrypted at rest and hidden from serialization —
 * per-employee pay is the most sensitive data in the system (SECURITY.md §7).
 * Wage/bank visibility is gated server-side by `payroll.view || employees.edit`;
 * controllers null these out of props for anyone without it.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property string $month
 * @property PayrollStatus $status
 * @property WageType|null $wage_type
 * @property PaymentMethod|null $payment_method
 * @property Carbon|null $paid_at
 * @property numeric-string $attendance_days
 * @property numeric-string $attendance_hours
 * @property numeric-string $overtime_hours
 * @property array<int, string>|null $deployment_notes
 * @property array<int, array<string, mixed>>|null $rate_periods
 * @property array<int, array<string, mixed>>|null $day_type_summary
 * @property numeric-string $expense_deductions
 */
class Payroll extends Model
{
    use Auditable;
    use BelongsToCompany;

    public string $auditModule = 'payroll';

    /**
     * Never write pay figures into the audit trail.
     *
     * @var list<string>
     */
    public array $auditExclude = [
        'wage_rate', 'base_salary', 'days_amount', 'hours_amount', 'overtime_pay',
        'reimbursements', 'project_expenses', 'gross_pay', 'advance_deductions',
        'fine_deductions', 'expense_deductions', 'other_deductions', 'manual_additions', 'net_amount',
        'rate_periods', 'day_type_summary',
    ];

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'month', 'attendance_days', 'attendance_hours', 'overtime_hours',
        'wage_type', 'wage_rate', 'base_salary', 'days_amount', 'hours_amount', 'rate_periods',
        'day_type_summary', 'overtime_pay', 'reimbursements', 'project_expenses', 'gross_pay',
        'advance_deductions', 'fine_deductions', 'expense_deductions', 'other_deductions', 'manual_additions',
        'net_amount', 'status', 'payment_method', 'paid_at', 'notes', 'deployment_notes',
    ];

    /** @var list<string> */
    protected $hidden = [
        'wage_rate', 'base_salary', 'days_amount', 'hours_amount', 'overtime_pay',
        'reimbursements', 'project_expenses', 'gross_pay', 'advance_deductions',
        'fine_deductions', 'expense_deductions', 'other_deductions', 'manual_additions', 'net_amount',
        'rate_periods', 'day_type_summary',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayrollStatus::class,
            'wage_type' => WageType::class,
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'attendance_days' => 'decimal:2',
            'attendance_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'deployment_notes' => 'array',
            // Per-rate + per-day-type breakdowns (contain pay figures) — encrypted JSON
            'rate_periods' => 'encrypted:array',
            'day_type_summary' => 'encrypted:array',
            // Money — encrypted at rest
            'wage_rate' => 'encrypted',
            'base_salary' => 'encrypted',
            'days_amount' => 'encrypted',
            'hours_amount' => 'encrypted',
            'overtime_pay' => 'encrypted',
            'reimbursements' => 'encrypted',
            'project_expenses' => 'encrypted',
            'gross_pay' => 'encrypted',
            'advance_deductions' => 'encrypted',
            'fine_deductions' => 'encrypted',
            'expense_deductions' => 'encrypted',
            'other_deductions' => 'encrypted',
            'manual_additions' => 'encrypted',
            'net_amount' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        // Deployed workers stay on their HOME company payroll, so the employee
        // can sit outside the payroll's company (Option A) — read unscoped.
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Every encrypted money column. Legacy rows hold PLAINTEXT values in some
     * of these (columns added by later migrations with a '0' default, or rows
     * written before a cast landed) — decrypt() rejects them.
     *
     * @var list<string>
     */
    private const ENCRYPTED_MONEY = [
        'wage_rate', 'base_salary', 'days_amount', 'hours_amount', 'overtime_pay',
        'reimbursements', 'project_expenses', 'gross_pay', 'advance_deductions',
        'fine_deductions', 'expense_deductions', 'other_deductions',
        'manual_additions', 'net_amount',
    ];

    /**
     * In-memory heal: any encrypted column whose stored payload cannot be
     * decrypted reads as 0 / null instead of throwing. Display-safety only —
     * nothing is persisted; recalculating the month rewrites the row properly.
     */
    public function healUndecryptable(): static
    {
        foreach (self::ENCRYPTED_MONEY as $column) {
            try {
                $this->getAttribute($column);
            } catch (DecryptException) {
                $this->setAttribute($column, '0');
            }
        }

        if ($this->ratePeriodsSafe() === null) {
            $this->setAttribute('rate_periods', null);
        }
        if ($this->dayTypeSummarySafe() === null) {
            $this->setAttribute('day_type_summary', null);
        }

        return $this;
    }

    /**
     * The rate-period breakdown, tolerating an undecryptable payload. Rows
     * written before the encrypted cast (or under a rotated APP_KEY) hold
     * values decrypt() rejects — display data must degrade to null, never 500
     * the payroll screen. A recalculation rewrites the value correctly.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function ratePeriodsSafe(): ?array
    {
        try {
            return $this->rate_periods;
        } catch (DecryptException) { // @phpstan-ignore catch.neverThrown (the encrypted cast throws at runtime on a bad payload — proven on staging)
            return null;
        }
    }

    /**
     * The day-type breakdown, with the same bad-payload tolerance.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function dayTypeSummarySafe(): ?array
    {
        try {
            return $this->day_type_summary;
        } catch (DecryptException) { // @phpstan-ignore catch.neverThrown (same runtime reality as ratePeriodsSafe)
            return null;
        }
    }
}
