<?php

namespace App\Enums;

/**
 * The per-module actions of the permission matrix (REQUIREMENTS.md §4).
 * Each maps to a can_{value} boolean on user_module_permissions.
 */
enum PermissionAction: string
{
    case View = 'view';
    case Create = 'create';
    case Edit = 'edit';
    case Delete = 'delete';
    case Upload = 'upload';
    case Download = 'download';
    case Export = 'export';
    case Approve = 'approve';
    case ApproveFinal = 'approve_final';

    public function column(): string
    {
        return 'can_'.$this->value;
    }
}
