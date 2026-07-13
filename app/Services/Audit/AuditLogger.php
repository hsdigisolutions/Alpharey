<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Single write-path for the audit trail (SECURITY.md §6). Captures the acting
 * user and request context (IP, user agent, method, URL) automatically.
 */
class AuditLogger
{
    public function created(Model $model): AuditLog
    {
        return $this->log('created', $model, null, $this->auditableAttributes($model));
    }

    public function updated(Model $model): ?AuditLog
    {
        $changes = $this->filterAttributes($model, $model->getChanges());

        if ($changes === []) {
            return null;
        }

        $original = array_intersect_key(
            $this->filterAttributes($model, $model->getOriginal()),
            $changes,
        );

        return $this->log('updated', $model, $original, $changes);
    }

    public function deleted(Model $model): AuditLog
    {
        return $this->log('deleted', $model, $this->auditableAttributes($model), null);
    }

    /**
     * Record any action ('viewed', 'exported', custom) with request context.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(
        string $action,
        ?Model $model = null,
        ?array $old = null,
        ?array $new = null,
        ?string $description = null,
        ?string $module = null,
    ): AuditLog {
        $user = Auth::user();
        $request = app()->bound('request') ? request() : null;

        return AuditLog::query()->create([
            'user_id' => $user?->getAuthIdentifier(),
            'user_name' => $user instanceof User ? $user->name : null,
            'user_email' => $user instanceof User ? $user->email : null,
            'company_id' => $model?->getAttribute('company_id') ?? app(CurrentCompany::class)->id(),
            'action' => $action,
            'module' => $module ?? ($model !== null ? $this->moduleFor($model) : null),
            'entity_name' => $model !== null ? $this->entityNameFor($model) : null,
            'model_type' => $model?->getMorphClass(),
            'model_id' => $model !== null ? (string) $model->getKey() : null,
            'description' => $description,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : substr((string) $request->userAgent(), 0, 500),
            'request_method' => $request?->method(),
            'request_url' => $request === null ? null : substr($request->fullUrl(), 0, 2048),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditableAttributes(Model $model): array
    {
        return $this->filterAttributes($model, $model->getAttributes());
    }

    /**
     * Strip hidden and per-model excluded attributes so secrets never reach
     * the audit trail.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterAttributes(Model $model, array $attributes): array
    {
        $excluded = array_merge(
            $model->getHidden(),
            ['created_at', 'updated_at'],
            property_exists($model, 'auditExclude') ? $model->auditExclude : [],
        );

        return array_diff_key($attributes, array_flip($excluded));
    }

    private function moduleFor(Model $model): ?string
    {
        return property_exists($model, 'auditModule') ? $model->auditModule : null;
    }

    private function entityNameFor(Model $model): ?string
    {
        foreach (['name', 'full_name', 'title'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
