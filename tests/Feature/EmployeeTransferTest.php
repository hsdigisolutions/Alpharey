<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EmployeeWageRate;
use App\Models\Payroll;
use App\Models\User;

/**
 * Feature 4 — transfer an employee between companies. Profile + wage history
 * move; attendance + payroll stay with the old company; equipment/paid-payroll
 * guards block the transfer.
 */
beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->sa = User::factory()->superAdmin()->create();
    $this->employee = Employee::factory()->forCompany($this->companyA)->create();
});

function seedWageRate(Employee $e, Company $c): EmployeeWageRate
{
    $r = new EmployeeWageRate(['wage_type' => 'daily', 'rate' => '50', 'effective_from' => '2026-01-01', 'is_default' => true]);
    $r->employee_id = $e->id;
    $r->company_id = $c->id;
    $r->save();

    return $r;
}

it('transfers the employee, moves wage history, keeps attendance with the old company', function (): void {
    $wage = seedWageRate($this->employee, $this->companyA);
    $att = Attendance::factory()->create([
        'company_id' => $this->companyA->id, 'employee_id' => $this->employee->id,
        'date' => '2026-08-10', 'status' => 'present',
    ]);

    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id,
        'transfer_date' => '2026-08-17',
    ])->assertRedirect();

    $fresh = $this->employee->fresh();
    expect($fresh->company_id)->toBe($this->companyB->id)
        ->and($fresh->previous_company_id)->toBe($this->companyA->id)
        ->and($fresh->documents_pending_reupload)->toBeTrue();

    // Wage history moved; attendance stayed with the old company.
    expect(EmployeeWageRate::withoutGlobalScopes()->find($wage->id)->company_id)->toBe($this->companyB->id)
        ->and(Attendance::withoutGlobalScopes()->find($att->id)->company_id)->toBe($this->companyA->id);

    $this->assertDatabaseHas('audit_logs', ['action' => 'transferred', 'module' => 'employees']);
});

it('blocks a transfer while equipment is outstanding', function (): void {
    EmployeeEquipmentIssue::factory()->create([
        'employee_id' => $this->employee->id, 'company_id' => $this->companyA->id,
    ]);

    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id, 'transfer_date' => '2026-08-17',
    ])->assertSessionHasErrors('transfer');

    expect($this->employee->fresh()->company_id)->toBe($this->companyA->id);
});

it('blocks a transfer in a paid payroll month', function (): void {
    $p = new Payroll(['month' => '2026-08']);
    $p->company_id = $this->companyA->id;
    $p->employee_id = $this->employee->id;
    $p->status = 'paid';
    $p->save();

    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id, 'transfer_date' => '2026-08-17',
    ])->assertSessionHasErrors('transfer');

    expect($this->employee->fresh()->company_id)->toBe($this->companyA->id);
});

it('denies transfer for a non-admin user', function (): void {
    $manager = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($manager)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id, 'transfer_date' => '2026-08-17',
    ])->assertForbidden();
});
