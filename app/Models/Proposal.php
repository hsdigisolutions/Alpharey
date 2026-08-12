<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use App\Enums\VatRate;
use App\Models\Concerns\Auditable;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Screen 18 — Proposals. SHARED across companies (dev skill Rule 1). VAT
 * optional (DECISIONS.md): vat_rate is a nullable VatRate, null = No aplica.
 *
 * @property int $id
 * @property string $number
 * @property ProposalStatus $status
 * @property VatRate|null $vat_rate
 * @property float|null $vat_custom_percent
 * @property Carbon|null $proposal_date
 * @property Carbon|null $expiry_date
 * @property array<int, array<string, mixed>>|null $line_items
 */
class Proposal extends Model
{
    use Auditable;

    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    public string $auditModule = 'proposals';

    /**
     * `number` is server-generated (never in the Form Request); the totals
     * are computed server-side in the controller. Neither is user-injectable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'number', 'client_id', 'project_id', 'proposal_date', 'expiry_date', 'description',
        'line_items', 'estimated_quantity', 'estimated_total', 'subtotal',
        'vat_rate', 'vat_custom_percent', 'vat_amount', 'total_amount', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'vat_rate' => VatRate::class,
            'vat_custom_percent' => 'float',
            'proposal_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'line_items' => 'array',
            'estimated_quantity' => 'decimal:2',
            'estimated_total' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public static function nextNumber(): string
    {
        $year = now()->year;
        $count = self::query()->whereYear('created_at', $year)->count();

        return sprintf('PROP-%d-%04d', $year, $count + 1);
    }
}
