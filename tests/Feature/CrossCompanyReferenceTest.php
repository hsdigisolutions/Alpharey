<?php

use App\Enums\BillingMethod;
use App\Enums\DeploymentStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Project;
use App\Models\User;

/**
 * Ownership is part of validity (OwnCompanyEmployee / OwnCompanyProject).
 *
 * Rule::exists() checks the raw table, so any company's id used to pass —
 * and because payroll gathers advances/expenses/measurements per EMPLOYEE
 * across companies (Option A), a foreign id injected money into, or
 * deducted it from, ANOTHER company's payslips. These tests pin the wall.
 */
beforeEach(function (): void {
    $this->mine = Company::factory()->create();
    $this->other = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->mine)->create();

    $this->foreignEmployee = Employee::factory()->forCompany($this->other)->create();
    $this->foreignProject = Project::factory()->forCompany($this->other)->create();
    $this->ownProject = Project::factory()->forCompany($this->mine)->create();
});

it('rejects an advance for another company employee', function (): void {
    $this->actingAs($this->admin)->post('/advances', [
        'employee_id' => $this->foreignEmployee->id,
        'amount' => 500,
        'request_date' => '2026-07-01',
        'payroll_month' => '2026-07',
    ])->assertSessionHasErrors('employee_id');
});

it('rejects an expense naming another company employee or project', function (): void {
    $base = [
        'type' => 'ticket', 'date' => '2026-07-01', 'subtotal' => 40,
    ];

    $this->actingAs($this->admin)->post('/expenses', $base + [
        'employee_id' => $this->foreignEmployee->id,
        'project_id' => $this->ownProject->id,
    ])->assertSessionHasErrors('employee_id');

    $this->actingAs($this->admin)->post('/expenses', $base + [
        'project_id' => $this->foreignProject->id,
    ])->assertSessionHasErrors('project_id');
});

it('rejects a measurement against another company employee or project', function (): void {
    $this->actingAs($this->admin)->post('/measurements', [
        'project_id' => $this->foreignProject->id,
        'description' => 'Muro norte',
        'type' => 'area',
        'quantity' => 12,
        'date' => '2026-07-01',
    ])->assertSessionHasErrors('project_id');
});

it('rejects attendance for another company employee unless deployed here', function (): void {
    $payload = [
        'employee_id' => $this->foreignEmployee->id,
        'date' => '2026-07-01',
        'mode' => 'project',
        'hours_worked' => 8,
        'status' => 'present',
    ];

    // Not deployed: refused.
    $this->actingAs($this->admin)->post('/attendance', $payload)
        ->assertSessionHasErrors('employee_id');

    // Deployed INTO the acting company: the legitimate Phase 5 case.
    $deployment = new EmployeeDeployment([
        'deployment_start' => '2026-07-01',
        'billing_method' => BillingMethod::OptionA->value,
        'rate_type' => 'hourly',
        'split_pct' => '100',
    ]);
    $deployment->employee_id = $this->foreignEmployee->id;
    $deployment->home_company_id = $this->other->id;
    $deployment->host_company_id = $this->mine->id;
    $deployment->project_id = $this->ownProject->id;
    $deployment->status = DeploymentStatus::Active;
    $deployment->save();

    $this->actingAs($this->admin)->post('/attendance', $payload)
        ->assertSessionDoesntHaveErrors('employee_id');
});

it('rejects an invoice against another company project', function (): void {
    $this->actingAs($this->admin)->post('/invoices', [
        'type' => 'sale',
        'sub_type' => 'standard',
        'client_id' => Client::factory()->create()->id,
        'invoice_date' => '2026-07-01',
        'project_id' => $this->foreignProject->id,
        'status' => 'draft',
        'lines' => [['description' => 'Obra', 'quantity' => 1, 'unit_price' => 100]],
    ])->assertSessionHasErrors('project_id');
});
