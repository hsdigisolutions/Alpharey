<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Screen 20 — Vendors. SHARED across companies (dev skill Rule 1) — no
 * BelongsToCompany. No soft delete (not in the soft-delete list).
 *
 * @property int $id
 * @property string $name
 * @property bool $active
 */
class Vendor extends Model
{
    use Auditable;

    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    public string $auditModule = 'vendors';

    /** @var list<string> */
    protected $fillable = [
        'name', 'company_name', 'nif', 'phone', 'alternate_phone', 'email',
        'address', 'area', 'city', 'country', 'postal_code', 'bank_account',
        'payment_terms', 'active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<VendorContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class);
    }

    /**
     * @return HasMany<VendorPaymentTerm, $this>
     */
    public function paymentTerms(): HasMany
    {
        return $this->hasMany(VendorPaymentTerm::class);
    }
}
