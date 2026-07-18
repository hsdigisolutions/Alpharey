<?php

namespace App\Enums;

/**
 * The notification types whose per-role delivery is configurable from
 * Settings (Screen 26). The document/vehicle expiry alerts predate this and
 * are always on for Company Admins; everything else added in Phase 8 is
 * gated through NotificationRules.
 */
enum NotificationType: string
{
    case DocumentExpiry = 'document_expiry';
    case PayrollReady = 'payroll_ready';
    case InvoiceOverdue = 'invoice_overdue';
    case AdvancePending = 'advance_pending';
    case LeavePending = 'leave_pending';
    case ProjectAlert = 'project_alert';
    case DeploymentEvent = 'deployment_event';

    /**
     * The default recipient roles for each type when no rule has been set.
     * Company Admins get the operational alerts; Super Admins additionally
     * see the cross-company ones.
     *
     * @return list<UserRole>
     */
    public function defaultRoles(): array
    {
        return match ($this) {
            self::DeploymentEvent => [UserRole::SuperAdmin, UserRole::CompanyAdmin],
            default => [UserRole::CompanyAdmin],
        };
    }
}
