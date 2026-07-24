<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';

    /**
     * Broad management access — bypasses the module-permission matrix within
     * all companies they are assigned to.  Super Admin can expand or restrict
     * their access further via the Permission Matrix screen.
     */
    case Admin = 'admin';

    /**
     * Company-level access — sees only their assigned company (or companies
     * when a Super Admin or Admin assigns them to multiple).  Module access
     * is controlled by the per-module toggle grid in the Permission Matrix.
     */
    case Manager = 'manager';

    /**
     * Site worker who only ever uses the mobile PWA: check in / check out /
     * report absence / see their own month.  They reach NO CRM module.  The
     * permission matrix does not apply to them, and a module right granted by
     * mistake would still grant nothing (ModulePermissions refuses the role).
     *
     * Client decision (2026-07-22): Workers are exempt from two-step
     * verification.  Email + password only.
     */
    case Worker = 'worker';

    /**
     * Roles an administrator may assign from the CRM user forms.  Worker is
     * deliberately absent — worker accounts are created from the employee
     * record, never here.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Manager];
    }
}
