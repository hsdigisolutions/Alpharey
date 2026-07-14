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

        return match ($this) {
            self::Employees => [$a::View, $a::Create, $a::Edit, $a::Delete, $a::Export, $a::Approve],
            self::Projects,
            self::Clients,
            self::Vendors,
            self::Vehicles,
            self::Inventory,
            self::Proposals => [$a::View, $a::Create, $a::Edit, $a::Delete, $a::Export],
            self::Invoices,
            self::Expenses,
            self::Deployments => [$a::View, $a::Create, $a::Edit, $a::Delete, $a::Export, $a::Approve],
            self::Attendance => [$a::View, $a::Create, $a::Edit, $a::Delete, $a::Export],
            self::Payroll => [$a::View, $a::Create, $a::Edit, $a::Download, $a::Export, $a::Approve],
            self::Documents => [$a::View, $a::Delete, $a::Upload, $a::Download, $a::Export, $a::Approve],
            self::Reports => [$a::View, $a::Export],
            self::CallPanel => [$a::View, $a::Create, $a::Edit, $a::Delete],
            self::CommissionReports => [$a::View, $a::Edit, $a::Export, $a::Approve],
            self::LeaveManagement,
            self::Measurements => [$a::View, $a::Create, $a::Edit, $a::Delete, $a::Export, $a::Approve],
        };
    }
}
