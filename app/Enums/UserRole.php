<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case CompanyAdmin = 'company_admin';
    case User = 'user';

    /**
     * A site worker who only ever uses the mobile PWA: check in, check out,
     * report an absence, see their own month. They reach NO CRM module — the
     * permission matrix does not apply to them, and a module right granted to
     * one would do nothing (ModulePermissions refuses the role outright).
     *
     * Client decision (2026-07-22): workers are EXEMPT from two-step
     * verification. A construction crew cannot be asked to keep an
     * authenticator app in sync on site, and the realistic alternative was
     * that nobody would use the app at all. Email + password only.
     */
    case Worker = 'worker';

    /**
     * Roles an administrator may assign from the CRM user forms. Worker is
     * deliberately absent: a worker login is meaningless without the employee
     * it belongs to, so those accounts are created from the employee record.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::CompanyAdmin, self::User];
    }
}
