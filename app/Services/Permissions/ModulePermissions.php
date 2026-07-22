<?php

namespace App\Services\Permissions;

use App\Enums\Module;
use App\Enums\PermissionAction;
use App\Models\User;
use App\Models\UserModulePermission;

/**
 * The module-permission engine (REQUIREMENTS.md §4, SECURITY.md §3).
 *
 *  - Super Admin      → everything, everywhere (via Gate::before)
 *  - Company Admin    → everything within their own company
 *  - Custom user      → per-action booleans on user_module_permissions
 *
 * Checks query the table directly (no cross-request cache) so permission
 * changes take effect immediately, as the spec requires.
 */
class ModulePermissions
{
    public function allows(User $user, Module $module, PermissionAction $action): bool
    {
        if (! $user->active) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        // A worker reaches the PWA and nothing else. Refusing the role here
        // rather than relying on an empty permission row means a matrix entry
        // created for one by mistake still grants nothing.
        if ($user->isWorker()) {
            return false;
        }

        if ($user->company_id === null) {
            return false;
        }

        if ($user->isCompanyAdmin()) {
            return true;
        }

        return UserModulePermission::query()
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module', $module->value)
            ->where($action->column(), true)
            ->exists();
    }

    /**
     * Gate ability name for a module + action, e.g. "employees.view".
     */
    public static function ability(Module $module, PermissionAction $action): string
    {
        return $module->value.'.'.$action->value;
    }
}
