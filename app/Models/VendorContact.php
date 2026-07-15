<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool $is_primary
 */
class VendorContact extends Model
{
    use Auditable;

    public string $auditModule = 'vendors';

    /** @var list<string> */
    protected $fillable = ['name', 'position', 'phone', 'email', 'is_primary', 'notes'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
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
