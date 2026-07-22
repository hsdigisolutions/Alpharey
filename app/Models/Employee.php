<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\WageType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string $employee_code
 * @property string $full_name
 * @property string|null $nif
 * @property string|null $nif_hash
 * @property bool $active
 * @property WageType|null $wage_type
 * @property PaymentMethod|null $payment_method
 * @property Carbon|null $joining_date
 * @property Carbon|null $leaving_date
 * @property numeric-string|null $commission_percent
 */
class Employee extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    use SoftDeletes;

    public string $auditModule = 'employees';

    /**
     * company_id intentionally absent (tenancy Rule 1); employee_code is
     * generated server-side; nif_hash derives from nif.
     *
     * @var list<string>
     */
    protected $fillable = [
        'full_name', 'nif', 'email', 'mobile', 'phone', 'city', 'address',
        'department', 'designation', 'team_leader_id', 'joining_date',
        'leaving_date', 'active', 'is_contracted', 'default_check_in',
        'default_check_out', 'wage_type', 'wage_rate', 'base_salary',
        'daily_wage', 'per_meter_rate', 'commission_percent', 'payment_method',
        'overtime_policy_id', 'supervisor_overtime_policy_id', 'iban',
        'bank_name', 'has_driving_license', 'has_company_vehicle', 'notes',
    ];

    /**
     * Encrypted values never reach the audit trail or serialized payloads
     * by accident: they are appended explicitly where permitted.
     *
     * @var list<string>
     */
    protected $hidden = [
        'nif', 'nif_hash', 'iban', 'bank_name',
        'wage_rate', 'base_salary', 'daily_wage', 'per_meter_rate',
    ];

    protected function casts(): array
    {
        return [
            'nif' => 'encrypted',
            'iban' => 'encrypted',
            'bank_name' => 'encrypted',
            'wage_rate' => 'encrypted',
            'base_salary' => 'encrypted',
            'daily_wage' => 'encrypted',
            'per_meter_rate' => 'encrypted',
            'wage_type' => WageType::class,
            'payment_method' => PaymentMethod::class,
            'joining_date' => 'date:Y-m-d',
            'leaving_date' => 'date:Y-m-d',
            'active' => 'boolean',
            'is_contracted' => 'boolean',
            'has_driving_license' => 'boolean',
            'has_company_vehicle' => 'boolean',
            'commission_percent' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Blind index so encrypted NIF stays searchable (SECURITY.md §7)
        static::saving(function (Employee $employee): void {
            if ($employee->isDirty('nif')) {
                $employee->nif_hash = self::hashNif($employee->nif);
            }
        });
    }

    public static function hashNif(?string $nif): ?string
    {
        if ($nif === null || trim($nif) === '') {
            return null;
        }

        return hash_hmac('sha256', strtoupper(preg_replace('/\s+/', '', $nif) ?? ''), (string) config('app.key'));
    }

    /**
     * The login this worker uses for the mobile PWA, when they have one.
     * Most employees never do — office staff use the CRM, and only site
     * workers are given an account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(self::class, 'team_leader_id');
    }

    /**
     * @return HasMany<EmployeeNote, $this>
     */
    public function employeeNotes(): HasMany
    {
        return $this->hasMany(EmployeeNote::class);
    }

    /**
     * @return HasMany<EmployeeCallLog, $this>
     */
    public function callLogs(): HasMany
    {
        return $this->hasMany(EmployeeCallLog::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Next sequential code, per company prefix (EMP-0001, …). Company id
     * is embedded so codes stay readable across the group.
     */
    public static function nextCode(int $companyId): string
    {
        $last = self::withTrashed()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->count();

        return sprintf('E%d-%04d', $companyId, $last + 1);
    }
}
