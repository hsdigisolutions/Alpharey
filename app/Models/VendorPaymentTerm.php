<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool $is_default
 */
class VendorPaymentTerm extends Model
{
    use Auditable;

    public string $auditModule = 'vendors';

    /** @var list<string> */
    protected $fillable = ['name', 'days', 'discount_percentage', 'discount_days', 'is_default', 'description'];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'discount_percentage' => 'decimal:2',
            'discount_days' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
