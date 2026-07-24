<?php

namespace App\Services\Permissions;

use App\Enums\Module;
use App\Enums\PermissionAction;
use App\Models\User;
use App\Models\UserModulePermission;

/**
 * The module-permission engine (REQUIREMENTS.md §4, SECURITY.md §3).
 *
 *  - Super Admin  → everything, everywhere (via Gate::before)
 *  - Admin        → everything within their assigned company/companies
 *  - Manager      → per-action booleans in user_module_permissions
 *  - Worker       → nothing (PWA only)
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

        // Workers reach the PWA and nothing else.  Refusing the role here
        // rather than relying on an empty permission row means a matrix entry
        // created for one by mistake still grants nothing.
        if ($user->isWorker()) {
            return false;
        }

        if ($user->company_id === null) {
            return false;
        }

        // Admins bypass the per-module matrix — they have full access to all
        // modules within their assigned company.
        if ($user->isAdmin()) {
            return true;
        }

        // Manager: evaluate the per-module row for their active company.
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
