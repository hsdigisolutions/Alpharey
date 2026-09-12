<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceSubType;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VatRate;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Screen 10 — Invoices. Company-owned. Totals are ALWAYS computed server-side
 * by InvoiceTotals from the line items; client-sent totals are never trusted
 * (same rule as Proposals). `number` is server-generated.
 *
 * VAT is nullable with no default — blank means "no VAT line" (DECISIONS.md
 * overrides the spec's "default 21%").
 *
 * @property int $id
 * @property int $company_id
 * @property string $number
 * @property InvoiceType $type
 * @property InvoiceSubType $sub_type
 * @property InvoiceStatus $status
 * @property PaymentStatus $payment_status
 * @property DiscountType|null $discount_type
 * @property PaymentMethod|null $payment_method
 * @property Carbon $invoice_date
 * @property Carbon|null $due_date
 * @property Carbon|null $payment_date
 * @property Carbon|null $overdue_notified_at
 * @property numeric-string $subtotal
 * @property VatRate|null $vat_rate
 * @property float|null $vat_custom_percent
 * @property numeric-string $vat_amount
 * @property numeric-string $discount_value
 * @property numeric-string $discount_amount
 * @property numeric-string|null $retention_percent
 * @property numeric-string $retention_amount
 * @property numeric-string $total
 * @property numeric-string $paid_amount
 * @property bool $is_taxable
 * @property int|null $counterparty_company_id
 * @property int|null $deployment_charge_id
 */
class Invoice extends Model
{
    use Auditable;
    use BelongsToCompany;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    public string $auditModule = 'invoices';

    /**
     * `number`, totals and payment_status are set by the server, never by a
     * Form Request — they are fillable so the service can assign them.
     *
     * @var list<string>
     */
    protected $fillable = [
        'number', 'type', 'sub_type', 'client_id', 'vendor_id', 'project_id',
        'invoice_date', 'due_date', 'billing_type', 'billing_period',
        'subtotal', 'vat_rate', 'vat_custom_percent', 'vat_amount', 'discount_type', 'discount_value',
        'discount_amount', 'retention_percent', 'retention_amount', 'total',
        'paid_amount', 'status', 'payment_status', 'payment_date',
        'payment_method', 'notes', 'is_taxable',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'sub_type' => InvoiceSubType::class,
            'status' => InvoiceStatus::class,
            'payment_status' => PaymentStatus::class,
            'discount_type' => DiscountType::class,
            'is_taxable' => 'boolean',
            'vat_rate' => VatRate::class,
            'vat_custom_percent' => 'float',
            'payment_method' => PaymentMethod::class,
            'invoice_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'payment_date' => 'date:Y-m-d',
            'overdue_notified_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'retention_percent' => 'decimal:2',
            'retention_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    /**
     * Next invoice number for a company: F{companyId}-{seq}, mirroring the
     * employee/project code convention.
     */
    public static function nextNumber(int $companyId): string
    {
        $last = static::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->value('number');

        $seq = is_string($last) && preg_match('/(\d+)$/', $last, $m) ? ((int) $m[1]) + 1 : 1;

        return sprintf('F%d-%05d', $companyId, $seq);
    }

    /**
     * Next inter-company deployment invoice number: DEP-{companyId}-{seq}, a
     * SEPARATE series from the client-facing F… numbers so the two never
     * interleave (audit clarity — client decision 2026-09-12).
     */
    public static function nextDeploymentNumber(int $companyId): string
    {
        $last = static::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('number', 'like', 'DEP-'.$companyId.'-%')
            ->orderByDesc('id')
            ->value('number');

        $seq = is_string($last) && preg_match('/(\d+)$/', $last, $m) ? ((int) $m[1]) + 1 : 1;

        return sprintf('DEP-%d-%05d', $companyId, $seq);
    }

    /** A deployment invoice bills another company, not an external client. */
    public function isDeploymentInvoice(): bool
    {
        return $this->deployment_charge_id !== null;
    }

    /**
     * @return HasMany<InvoiceLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(InvoiceLineItem::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The company being billed on an inter-company deployment invoice (the
     * host). NOT tenancy-scoped — Company has no CompanyScope — so it resolves
     * for both the home issuer and, on the Deployments PDF, the host viewer.
     *
     * @return BelongsTo<Company, $this>
     */
    public function counterpartyCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'counterparty_company_id');
    }

    /**
     * @return BelongsTo<DeploymentCharge, $this>
     */
    public function deploymentCharge(): BelongsTo
    {
        return $this->belongsTo(DeploymentCharge::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
