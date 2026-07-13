<?php

namespace App\Models\Concerns;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Apply to every observed model (CLAUDE.md: "all models are observed and
 * logged to audit_logs"). Optional per-model configuration:
 *
 *   public array $auditExclude = ['some_noisy_column'];  // never logged
 *   public string $auditModule = 'employees';            // module tag
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => app(AuditLogger::class)->created($model));
        static::updated(fn (Model $model) => app(AuditLogger::class)->updated($model));
        static::deleted(fn (Model $model) => app(AuditLogger::class)->deleted($model));
    }
}
