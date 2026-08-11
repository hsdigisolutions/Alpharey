<?php

namespace App\Enums;

/**
 * Notification types. Two families:
 *
 *  - ROLE-BASED — delivered to a set of ROLES within a company; who receives
 *    them is configurable in the Settings → Notification Rules matrix
 *    (NotificationRules). `defaultRoles()` is the baseline before any rule.
 *  - WORKER-DIRECT — delivered to a SPECIFIC user (a worker's own approval
 *    result, an invited weekend offer, a call assigned to one user). These are
 *    NOT role-configurable, so they are hidden from the matrix.
 */
enum NotificationType: string
{
    // Documents
    case DocumentExpiry = 'document_expiry';
    case DocumentExpired = 'document_expired';
    // Payroll
    case PayrollReady = 'payroll_ready';
    case PayrollApproved = 'payroll_approved';
    // Invoices
    case InvoiceOverdue = 'invoice_overdue';
    case InvoicePaid = 'invoice_paid';
    // Requests pending review
    case AdvancePending = 'advance_pending';
    case ExpensePending = 'expense_pending';
    case LeavePending = 'leave_pending';
    // Attendance / vehicles
    case WorkerGpsMissing = 'worker_gps_missing';
    case ShortHours = 'short_hours';
    case AutoAbsent = 'auto_absent';
    case VehicleExpiry = 'vehicle_expiry';
    case VehicleNotReturned = 'vehicle_not_returned';
    // Legacy Phase-8
    case ProjectAlert = 'project_alert';
    case DeploymentEvent = 'deployment_event';
    // Worker-direct (not in the role matrix)
    case AdvanceDecided = 'advance_decided';
    case ExpenseDecided = 'expense_decided';
    case LeaveDecided = 'leave_decided';
    case WeekendOffer = 'weekend_offer';
    case CallFollowUp = 'call_follow_up';

    /**
     * Sent to ONE specific user, not a role set — excluded from the matrix.
     */
    public function isWorkerDirect(): bool
    {
        return match ($this) {
            self::AdvanceDecided, self::ExpenseDecided, self::LeaveDecided,
            self::WeekendOffer, self::CallFollowUp => true,
            default => false,
        };
    }

    /**
     * The default recipient roles when no matrix rule has been set (role-based
     * types only). Mirrors the Settings-screen defaults.
     *
     * @return list<UserRole>
     */
    public function defaultRoles(): array
    {
        return match ($this) {
            // Expired documents + deployment events also reach the group owner.
            self::DocumentExpired,
            self::DeploymentEvent => [UserRole::SuperAdmin, UserRole::Admin],
            // Sign-off / money-settled events are Super-Admin-visible.
            self::PayrollApproved,
            self::InvoicePaid => [UserRole::SuperAdmin],
            // Requests awaiting review + short-shift alerts reach the managers
            // who action them too.
            self::AdvancePending,
            self::ExpensePending,
            self::LeavePending,
            self::ShortHours => [UserRole::Admin, UserRole::Manager],
            // Worker-direct types go to one specific user, never a role.
            self::AdvanceDecided, self::ExpenseDecided, self::LeaveDecided,
            self::WeekendOffer, self::CallFollowUp => [],
            // Everything else (document expiry, payroll ready, invoice overdue,
            // GPS-missing, auto-absent, vehicle alerts, project alerts) →
            // the owning company's Admins.
            default => [UserRole::Admin],
        };
    }

    /**
     * An emoji glyph for the bell / list, keyed by kind of event.
     */
    public function icon(): string
    {
        return match ($this) {
            self::DocumentExpiry, self::DocumentExpired => '📄',
            self::PayrollReady, self::PayrollApproved => '✅',
            self::InvoiceOverdue, self::InvoicePaid => '💰',
            self::AdvancePending, self::AdvanceDecided => '💵',
            self::ExpensePending, self::ExpenseDecided => '👷',
            self::LeavePending, self::LeaveDecided => '🌴',
            self::WorkerGpsMissing => '📍',
            self::ShortHours => '⏱️',
            self::AutoAbsent => '🚫',
            self::VehicleExpiry, self::VehicleNotReturned => '🚗',
            self::CallFollowUp => '📞',
            self::WeekendOffer => '📅',
            self::ProjectAlert, self::DeploymentEvent => '🔔',
        };
    }

    /**
     * Coarse category for the notifications-page filter tabs.
     */
    public function category(): string
    {
        return match ($this) {
            self::DocumentExpiry, self::DocumentExpired => 'documents',
            self::PayrollReady, self::PayrollApproved => 'payroll',
            self::InvoiceOverdue, self::InvoicePaid => 'invoices',
            self::VehicleExpiry, self::VehicleNotReturned => 'vehicles',
            self::AdvancePending, self::AdvanceDecided,
            self::ExpensePending, self::ExpenseDecided,
            self::LeavePending, self::LeaveDecided,
            self::WorkerGpsMissing, self::ShortHours, self::AutoAbsent, self::WeekendOffer => 'workers',
            default => 'other',
        };
    }
}
