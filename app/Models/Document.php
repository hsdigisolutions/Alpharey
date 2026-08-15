<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Unified polymorphic document store. Files live under
 * storage/app/private (disk "private"), reachable only through the
 * permission-checked download endpoint. Soft deletes: metadata is never
 * lost; the audit trail records every action.
 *
 * @property string $id
 * @property int|null $company_id
 * @property string $category
 * @property string $type_key
 * @property string|null $name
 * @property string|null $original_name
 * @property string|null $file_path
 * @property int $version
 * @property bool $is_current
 * @property bool|null $has_flag
 * @property bool $is_exempt
 * @property Carbon|null $expiry_notified_at
 * @property Carbon|null $issue_date
 * @property Carbon|null $expiry_date
 * @property array<string, mixed>|null $metadata
 * @property list<array<string, string|null>>|null $contacts
 * @property string|null $documentable_type
 */
class Document extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;

    public string $auditModule = 'documents';

    /** @var list<string> */
    protected $fillable = [
        'category', 'type_key', 'name', 'original_name', 'mime', 'size',
        'has_flag', 'issue_date', 'expiry_date', 'is_exempt', 'notes',
        'metadata', 'contacts',
    ];

    /** file_path stays out of every payload — downloads go through the controller */
    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'has_flag' => 'boolean',
            'is_exempt' => 'boolean',
            'issue_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'is_current' => 'boolean',
            'expiry_notified_at' => 'datetime',
            'size' => 'integer',
            'version' => 'integer',
            'metadata' => 'array',
            'contacts' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The user who uploaded this version (for version-history display).
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
