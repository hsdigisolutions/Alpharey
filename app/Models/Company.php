<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use Auditable;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use SoftDeletes;

    public string $auditModule = 'companies';

    protected $fillable = [
        'brand_id',
        'name',
        'cif',
        'province',
        'address',
        'city',
        'postal_code',
        'phone',
        'email',
        'website',
        'logo_path',
        'status',
        'notes',
    ];

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
