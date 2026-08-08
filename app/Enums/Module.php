<?php

namespace App\Enums;

/**
 * The restrictable modules of the permission matrix (REQUIREMENTS.md §4).
 */
enum Module: string
{
    case Employees = 'employees';
    case Projects = 'projects';
    case Clients = 'clients';
    case Invoices = 'invoices';
    case Expenses = 'expenses';
    case Attendance = 'attendance';
    case Payroll = 'payroll';
    case Documents = 'documents';
    case Reports = 'reports';
    case CallPanel = 'call_panel';
    case Proposals = 'proposals';
    case CommissionReports = 'commission_reports';
    case Vendors = 'vendors';
    case Vehicles = 'vehicles';
    case LeaveManagement = 'leave_management';
    case Measurements = 'measurements';
    case Inventory = 'inventory';
    case Deployments = 'deployments';
    case Subcontractors = 'subcontractors';

    /**
     * The actions that apply to this module — the "—" cells of the
     * permission matrix (REQUIREMENTS.md Screen 17). Non-applicable
     * actions are never granted and render as a dash.
     *
     * @return list<PermissionAction>
     */
    public function actions(): array
    {
        $a = PermissionAction::class;

        // Only actions a real gate checks are shown — a toggle that grants a
        // permission no code reads would mislead the admin. Modules omit Export
        // when they have no export feature, Approve when they have no approval
        // workflow, Delete when the record is never deleted.
        return match ($this) {
            // Modules with a full CRUD + Excel/PDF export.
            self::Employees,
            self::Projects,
            self::Clients,
            self::Invoices,
            self::Proposals => [$a::View, $a::Create, $a::Edit, $a::Delete, $a::Export],
            // CRUD without an export feature.
            self::Vendors,
            self::Vehicles,
            self::Inventory,
            self::Subcontractors => [$a::View, $a::Create, $a::Edit, $a::Delete],
            self::Expenses => [$a::View, $a::Create, $a::Edit, $a::Delete, $a::Export, $a::Approve],
            // Deployments: create → complete/cancel + a cross-charge approval;
            // never deleted (decision 25), no export.
            self::Deployments => [$a::View, $a::Create, $a::Edit, $a::Approve],
            self::Attendance => [$a::View, $a::Create, $a::Edit, $a::Delete, $a::Export],
            self::Payroll => [$a::View, $a::Create, $a::Edit, $a::Download, $a::Export, $a::Approve],
            self::Documents => [$a::View, $a::Delete, $a::Upload, $a::Download, $a::Approve],
            self::Reports => [$a::View, $a::Export],
            self::CallPanel => [$a::View, $a::Create, $a::Edit, $a::Delete],
            self::CommissionReports => [$a::View, $a::Edit, $a::Export, $a::Approve],
            // Leave + Measurements: CRUD + approval, no export feature.
            self::LeaveManagement,
            self::Measurements => [$a::View, $a::Create, $a::Edit, $a::Delete, $a::Approve],
        };
    }
}
