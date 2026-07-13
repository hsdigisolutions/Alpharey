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
}
