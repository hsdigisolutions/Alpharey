<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserModulePermission extends Model
{
    use Auditable;

    public string $auditModule = 'permissions';

    protected $fillable = [
        'user_id',
        'company_id',
        'module',
        'can_view',
        'can_create',
        'can_edit',
        'can_delete',
        'can_upload',
        'can_download',
        'can_export',
        'can_approve',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_create' => 'boolean',
            'can_edit' => 'boolean',
            'can_delete' => 'boolean',
            'can_upload' => 'boolean',
            'can_download' => 'boolean',
            'can_export' => 'boolean',
            'can_approve' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
