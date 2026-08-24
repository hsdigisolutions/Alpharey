<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EmployeeWageRate;
use App\Models\Payroll;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * Change 2 — transfer an employee between companies KEEPING history. The old
 * record stays (marked Transferred), a new linked record is created in the new
 * company; attendance/payroll/wage-history stay with the old company;
 * equipment/paid-payroll guards block the transfer.
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

it('keeps the old record as Transferred and creates a new linked record in the new company', function (): void {
    $wage = seedWageRate($this->employee, $this->companyA);
    $att = Attendance::factory()->create([
        'company_id' => $this->companyA->id, 'employee_id' => $this->employee->id,
        'date' => '2026-08-10', 'status' => 'present',
    ]);

    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id,
        'transfer_date' => '2026-08-17',
    ])->assertRedirect();

    // OLD record: stays with company A, marked Transferred, deactivated.
    $old = $this->employee->fresh();
    expect($old->company_id)->toBe($this->companyA->id)
        ->and($old->transferred_out_at)->not->toBeNull()
        ->and($old->active)->toBeFalse()
        ->and($old->status())->toBe('transferred')
        ->and($old->person_uuid)->not->toBeNull();

    // NEW record: in company B, same person link, joining date = the transfer date.
    $new = Employee::withoutGlobalScopes()
        ->where('person_uuid', $old->person_uuid)
        ->where('company_id', $this->companyB->id)
        ->firstOrFail();
    expect($new->id)->not->toBe($old->id)
        ->and($new->previous_company_id)->toBe($this->companyA->id)
        ->and($new->joining_date->toDateString())->toBe('2026-08-17')
        ->and($new->transferred_out_at)->toBeNull()
        ->and($new->active)->toBeTrue()
        ->and($new->documents_pending_reupload)->toBeTrue();

    // Old record's attendance + wage history stay with company A; the new record
    // gets its own freshly seeded wage rate.
    expect(Attendance::withoutGlobalScopes()->find($att->id)->company_id)->toBe($this->companyA->id)
        ->and(EmployeeWageRate::withoutGlobalScopes()->find($wage->id)->company_id)->toBe($this->companyA->id)
        ->and(EmployeeWageRate::withoutGlobalScopes()->where('employee_id', $new->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', ['action' => 'transferred', 'module' => 'employees']);
});

it('moves the worker login to the new record and excludes the old record from dropdowns', function (): void {
    $user = User::factory()->create(['role' => 'worker']);
    $this->employee->user_id = $user->id;
    $this->employee->save();

    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id, 'transfer_date' => '2026-08-17',
    ])->assertRedirect();

    $old = $this->employee->fresh();
    $new = Employee::withoutGlobalScopes()->where('person_uuid', $old->person_uuid)
        ->where('company_id', $this->companyB->id)->firstOrFail();

    // Login moved to the new active record; released from the old one.
    expect($old->user_id)->toBeNull()->and($new->user_id)->toBe($user->id);

    // The transferred-out record is excluded from selection dropdowns (scopeActive).
    expect(Employee::withoutGlobalScope(CompanyScope::class)->active()->pluck('id')->all())
        ->not->toContain($old->id)
        ->toContain($new->id);
});

it('carries personal documents over to the new record but not employment documents', function (): void {
    // A carry-over doc (DNI) and a non-carry doc (contrato) on the old record.
    $dni = new Document(['category' => 'personal', 'type_key' => 'dni', 'name' => null]);
    $dni->documentable()->associate($this->employee);
    $dni->company_id = $this->companyA->id;
    $dni->version = 1;
    $dni->setAttribute('is_current', true);
    $dni->save();

    $contrato = new Document(['category' => 'employment', 'type_key' => 'contrato_trabajo', 'name' => null]);
    $contrato->documentable()->associate($this->employee);
    $contrato->company_id = $this->companyA->id;
    $contrato->version = 1;
    $contrato->setAttribute('is_current', true);
    $contrato->save();

    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id, 'transfer_date' => '2026-08-17',
    ])->assertRedirect();

    $new = Employee::withoutGlobalScopes()->where('person_uuid', $this->employee->fresh()->person_uuid)
        ->where('company_id', $this->companyB->id)->firstOrFail();

    $newDocs = Document::withoutGlobalScopes()
        ->where('documentable_type', $new->getMorphClass())->where('documentable_id', $new->id)
        ->pluck('type_key')->all();

    expect($newDocs)->toContain('dni')->not->toContain('contrato_trabajo');
});

it('ships the employment history for both stints on the employee detail page', function (): void {
    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id, 'transfer_date' => '2026-08-17',
    ]);

    $new = Employee::withoutGlobalScopes()->where('person_uuid', $this->employee->fresh()->person_uuid)
        ->where('company_id', $this->companyB->id)->firstOrFail();

    $this->actingAs($this->sa)->get("/employees/{$new->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->has('employmentHistory', 2)
            ->where('employmentHistory.0.is_current', true) // current stint first
        );
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
