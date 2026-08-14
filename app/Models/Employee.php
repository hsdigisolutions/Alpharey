<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\WageType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Services\Workers\WorkerConsentService;
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
 * @property bool $can_use_vehicles
 * @property WageType|null $wage_type
 * @property PaymentMethod|null $payment_method
 * @property Carbon|null $joining_date
 * @property Carbon|null $leaving_date
 * @property numeric-string|null $commission_percent
 * @property Carbon|null $privacy_notice_ack_at
 * @property int|null $privacy_notice_ack_version
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
        'department', 'designation', 'designation_id', 'team_leader_id', 'joining_date',
        'leaving_date', 'active', 'is_contracted', 'default_check_in',
        'default_check_out', 'wage_type', 'wage_rate', 'base_salary',
        'daily_wage', 'per_meter_rate', 'commission_percent', 'payment_method',
        'overtime_policy_id', 'supervisor_overtime_policy_id', 'iban',
        'bank_name', 'has_driving_license', 'has_company_vehicle',
        'can_use_vehicles', 'works_at_height', 'notes',
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
            'privacy_notice_ack_at' => 'datetime',
            'active' => 'boolean',
            'is_contracted' => 'boolean',
            'has_driving_license' => 'boolean',
            'has_company_vehicle' => 'boolean',
            'can_use_vehicles' => 'boolean',
            'works_at_height' => 'boolean',
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
     * All privacy-consent records for this worker (append-only legal evidence),
     * newest first.
     *
     * @return HasMany<WorkerConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(WorkerConsent::class)->latest('consented_at');
    }

    /** The consent currently in force (current version, not revoked). */
    public function activeConsent(): ?WorkerConsent
    {
        return app(WorkerConsentService::class)->activeConsent($this);
    }

    /**
     * Has this worker accepted the CURRENT version of the notice (the mandatory
     * attendance acknowledgement)? The single gate the PWA + server both use.
     */
    public function hasAcknowledgedPrivacyNotice(): bool
    {
        return app(WorkerConsentService::class)->hasConsented($this);
    }

    /** Optional GPS consent — false when withheld or revoked. */
    public function consentGps(): bool
    {
        return $this->activeConsent()?->consent_gps === true;
    }

    /** Optional selfie consent — false when withheld or revoked. */
    public function consentPhoto(): bool
    {
        return $this->activeConsent()?->consent_photo === true;
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
     * @return BelongsTo<Designation, $this>
     */
    public function designationType(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
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

    /**
     * The rate to DISPLAY for this worker: the field matching their own wage
     * type. Reading the hourly `wage_rate` column for every type shows 0/blank
     * for daily, monthly and per-meter workers (wage-history sync nulls the
     * non-matching columns). Display only — pricing always goes through
     * WageRateService.
     */
    public function displayRate(): ?string
    {
        $value = match ($this->wage_type) {
            WageType::Daily => $this->getAttribute('daily_wage'),
            WageType::Monthly => $this->getAttribute('base_salary'),
            WageType::PerMeter => $this->getAttribute('per_meter_rate'),
            default => $this->getAttribute('wage_rate'),
        };

        return $value !== null ? (string) $value : null;
    }
}
