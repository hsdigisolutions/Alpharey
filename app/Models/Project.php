<?php

namespace App\Models;

use App\Enums\BillingType;
use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Enums\VatRate;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Screens 08/09 — Projects. Company-owned (BelongsToCompany); the client
 * is shared. Codes generated P{companyId}-{seq}.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property ProjectStatus $status
 * @property ProjectPriority $priority
 * @property BillingType|null $billing_type
 * @property VatRate|null $vat_rate
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property bool $outsourced
 * @property numeric-string|null $client_hour_rate
 * @property numeric-string|null $client_meter_rate
 * @property numeric-string|null $outsource_cost
 */
class Project extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    public string $auditModule = 'projects';

    /** @var list<string> */
    protected $fillable = [
        'client_id', 'name', 'project_type', 'status', 'priority',
        'billing_type', 'vat_rate', 'jefe_de_obra', 'jefe_phone', 'jefe_email',
        'encargado', 'seguridad', 'coordinator', 'start_date', 'end_date',
        'budget', 'estimated_hours', 'estimated_meters', 'outsourced',
        'client_hour_rate', 'client_meter_rate', 'outsource_cost',
        'outsourced_employee_id', 'google_drive_link', 'document_url',
        'forma_de_pago', 'fecha_de_cobro', 'pre_invoice_rule', 'invoice_rule',
        'due_rule', 'color_code', 'description',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'priority' => ProjectPriority::class,
            'billing_type' => BillingType::class,
            'vat_rate' => VatRate::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'budget' => 'decimal:2',
            'estimated_hours' => 'decimal:2',
            'estimated_meters' => 'decimal:2',
            'client_hour_rate' => 'decimal:2',
            'client_meter_rate' => 'decimal:2',
            'outsource_cost' => 'decimal:2',
            'outsourced' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<ProjectEmployeeRate, $this>
     */
    public function employeeRates(): HasMany
    {
        return $this->hasMany(ProjectEmployeeRate::class);
    }

    /**
     * @return HasMany<ProjectDesignationRate, $this>
     */
    public function designationRates(): HasMany
    {
        return $this->hasMany(ProjectDesignationRate::class);
    }

    /**
     * @return HasMany<Alert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * @return HasMany<ReportRemark, $this>
     */
    public function remarks(): HasMany
    {
        return $this->hasMany(ReportRemark::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public static function nextCode(int $companyId): string
    {
        $count = self::withoutGlobalScopes()->where('company_id', $companyId)->count();

        return sprintf('P%d-%04d', $companyId, $count + 1);
    }
}
