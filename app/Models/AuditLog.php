<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Append-only activity trail (SECURITY.md §6). Rows are written exclusively
 * through App\Services\Audit\AuditLogger; any update or delete attempt throws.
 * In production the app DB user is additionally denied UPDATE/DELETE on the
 * table at the MySQL grant level.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'company_id',
        'action',
        'module',
        'entity_name',
        'model_type',
        'model_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'request_method',
        'request_url',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Audit logs are append-only and cannot be modified.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Audit logs are append-only and cannot be deleted.');
        });
    }
}
