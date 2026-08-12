<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single worker privacy-consent record — legal evidence, append-only. Never
 * edited except to stamp `revoked_at`/`revoked_reason` when superseded. Written
 * only through WorkerConsentService.
 *
 * NOT company-scoped (BelongsToCompany): a worker has no CRM session, so
 * company_id is set explicitly from the employee; the admin reaches these rows
 * through the tenant-scoped Employee.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $user_id
 * @property int $company_id
 * @property string $consent_version
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $consented_at
 * @property string $timezone
 * @property bool $consent_attendance
 * @property bool $consent_gps
 * @property bool $consent_photo
 * @property string $consent_text_shown
 * @property string $language
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 */
class WorkerConsent extends Model
{
    use Auditable;

    public string $auditModule = 'employees';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'user_id', 'company_id', 'consent_version', 'ip_address',
        'user_agent', 'consented_at', 'timezone', 'consent_attendance',
        'consent_gps', 'consent_photo', 'consent_text_shown', 'language',
        'revoked_at', 'revoked_reason',
    ];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'revoked_at' => 'datetime',
            'consent_attendance' => 'boolean',
            'consent_gps' => 'boolean',
            'consent_photo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Currently in force: current-version and not revoked. */
    public function isActive(string $currentVersion): bool
    {
        return $this->revoked_at === null && $this->consent_version === $currentVersion;
    }
}
