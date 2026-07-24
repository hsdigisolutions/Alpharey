<?php

use App\Enums\InvoiceType;
use App\Enums\PayrollStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Models\Vendor;

/**
 * The finance detail tabs (task #48): employee Nómina, project Facturas/Gastos,
 * client Facturas, vendor Gastos. They are a read-only view of the same rows
 * the standalone screens own. The load-bearing behaviour is the gating:
 *
 *  - pay data (payroll) only reaches a wage viewer
 *  - client/vendor are SHARED records, but their finance rows are company-owned,
 *    so a company sees only ITS OWN invoices/expenses to a shared party.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'company_id' => $this->company->id,
    ]);
});

it('shows an employee payroll history to a wage viewer', function (): void {
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $payroll = new Payroll(['employee_id' => $employee->id, 'month' => '2026-04']);
    $payroll->company_id = $this->company->id;
    $payroll->gross_pay = '1000';
    $payroll->net_amount = '840';
    $payroll->status = PayrollStatus::Paid;
    $payroll->save();

    $this->actingAs($this->admin)
        ->get("/employees/{$employee->id}")
        ->assertInertia(fn ($page) => $page
            ->where('canSeeWages', true)
            ->where('payroll.0.month', '2026-04')
            ->where('payroll.0.net_amount', fn ($net) => (float) $net === 840.0));
});

it('hides payroll entirely from a user without the wage right', function (): void {
    // A custom user granted employees.view but NOT payroll.view / employees.edit.
    $user = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id,
        'module' => 'employees', 'can_view' => true, 'granted_by' => $user->id,
    ]);

    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $payroll = new Payroll(['employee_id' => $employee->id, 'month' => '2026-04']);
    $payroll->company_id = $this->company->id;
    $payroll->net_amount = '840';
    $payroll->save();

    $this->actingAs($user)
        ->get("/employees/{$employee->id}")
        ->assertInertia(fn ($page) => $page
            ->where('canSeeWages', false)
            ->where('payroll', []));
});

it('scopes the project finance tabs to the project', function (): void {
    $project = Project::factory()->create(['company_id' => $this->company->id]);
    $other = Project::factory()->create(['company_id' => $this->company->id]);

    Invoice::factory()->create(['company_id' => $this->company->id, 'type' => InvoiceType::Sale, 'project_id' => $project->id]);
    Invoice::factory()->create(['company_id' => $this->company->id, 'type' => InvoiceType::Sale, 'project_id' => $other->id]);
    Expense::factory()->create(['company_id' => $this->company->id, 'project_id' => $project->id]);

    $this->actingAs($this->admin)
        ->get("/projects/{$project->id}")
        ->assertInertia(fn ($page) => $page
            ->where('canViewInvoices', true)
            ->has('invoices', 1)
            ->has('expenses', 1));
});

it('shows a client only THIS company invoices, never another company to the same shared client', function (): void {
    // The client is a shared record both companies use.
    $client = Client::factory()->create();
    $other = Company::factory()->create();

    Invoice::factory()->create(['company_id' => $this->company->id, 'type' => InvoiceType::Sale, 'client_id' => $client->id]);
    // Another company's invoice to the SAME client must not appear.
    Invoice::factory()->create(['company_id' => $other->id, 'type' => InvoiceType::Sale, 'client_id' => $client->id]);

    $this->actingAs($this->admin)
        ->get("/clients/{$client->id}")
        ->assertInertia(fn ($page) => $page->has('invoices', 1));
});

it('shows a vendor only THIS company expenses to the shared vendor', function (): void {
    $vendor = Vendor::factory()->create();
    $other = Company::factory()->create();

    Expense::factory()->create(['company_id' => $this->company->id, 'vendor_id' => $vendor->id]);
    Expense::factory()->create(['company_id' => $other->id, 'vendor_id' => $vendor->id]);

    $this->actingAs($this->admin)
        ->get("/vendors/{$vendor->id}")
        ->assertInertia(fn ($page) => $page->has('expenses', 1));
});

it('withholds the client Facturas tab from a user without invoices.view', function (): void {
    $user = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->company->id,
        'module' => 'clients', 'can_view' => true, 'granted_by' => $user->id,
    ]);
    $client = Client::factory()->create();
    Invoice::factory()->create(['company_id' => $this->company->id, 'type' => InvoiceType::Sale, 'client_id' => $client->id]);

    $this->actingAs($user)
        ->get("/clients/{$client->id}")
        ->assertInertia(fn ($page) => $page
            ->where('canViewInvoices', false)
            ->where('invoices', []));
});
