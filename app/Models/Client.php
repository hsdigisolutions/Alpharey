<?php

namespace App\Models;

use App\Enums\ClientType;
use App\Models\Concerns\Auditable;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Screen 07 — Clients. SHARED across all companies by design (dev skill
 * Rule 1) — deliberately NO BelongsToCompany. Soft-deleted; audited.
 *
 * @property int $id
 * @property string $name
 * @property ClientType $client_type
 * @property bool $active
 */
class Client extends Model
{
    use Auditable;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use SoftDeletes;

    public string $auditModule = 'clients';

    /** @var list<string> */
    protected $fillable = [
        'name', 'company_name', 'nif', 'vat_number', 'client_type',
        'contact_person', 'phone', 'mobile', 'email', 'address', 'city',
        'postal_code', 'country', 'website', 'bank_account', 'payment_terms',
        'industry', 'company_size', 'preferred_contact', 'active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'client_type' => ClientType::class,
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ClientContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }

    /**
     * @return HasMany<ClientCommunication, $this>
     */
    public function communications(): HasMany
    {
        return $this->hasMany(ClientCommunication::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Proposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /**
     * Documents for this client. The client record is shared across companies,
     * but each document carries the acting company's company_id (set on upload),
     * so a company sees only its own paperwork for the shared client — the same
     * rule the client's invoices follow.
     *
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
