<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use Auditable;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use SoftDeletes;

    public string $auditModule = 'companies';

    /**
     * The name shown to WORKERS — the short brand name when set, else the legal
     * name. The single fallback authority (worker PWA + payslip use this).
     */
    public function displayName(): string
    {
        $brand = trim((string) $this->brand_name);

        return $brand !== '' ? $brand : (string) $this->name;
    }

    protected $fillable = [
        'brand_id',
        'name',
        'brand_name',
        'cif',
        'ccc',
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

    /**
     * The company's own official documents (the 13 types).
     *
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
