<?php

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeCompanyHistory;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EmployeeWageRate;
use App\Models\Payroll;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * Change 2 (SINGLE-RECORD model) — a transfer flips the ONE employee record's
 * company_id in place. It never creates a new record and never touches the
 * attendance table, so history can't be stranded and round trips can't
 * proliferate records. The company stint is logged in employee_company_history;
 * the open wage period is carried into the new company; equipment/paid-payroll
 * guards block the transfer.
 */
beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->sa = User::factory()->superAdmin()->create();
    $this->employee = Employee::factory()->forCompany($this->companyA)->create([
        'wage_type' => 'daily', 'daily_wage' => '50', 'joining_date' => '2026-01-01',
    ]);
});

function seedWageRate(Employee $e, Company $c): EmployeeWageRate
{
    $r = new EmployeeWageRate(['wage_type' => 'daily', 'rate' => '50', 'effective_from' => '2026-01-01', 'is_default' => true]);
    $r->employee_id = $e->id;
    $r->company_id = $c->id;
    $r->save();

    return $r;
}

it('flips the same record to the new company in place, keeping the record and its attendance', function (): void {
    $wage = seedWageRate($this->employee, $this->companyA);
    $att = Attendance::factory()->create([
        'company_id' => $this->companyA->id, 'employee_id' => $this->employee->id,
        'date' => '2026-08-10', 'status' => 'present',
    ]);
    $originalId = $this->employee->id;

    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id,
        'transfer_date' => '2026-08-17',
    ])->assertRedirect();

    // SAME record — id unchanged, now at company B, still active, stamps set.
    $emp = $this->employee->fresh();
    expect($emp->id)->toBe($originalId)
        ->and($emp->company_id)->toBe($this->companyB->id)
        ->and($emp->active)->toBeTrue()
        ->and($emp->transferred_out_at)->toBeNull()
        ->and($emp->previous_company_id)->toBe($this->companyA->id)
        ->and($emp->transferred_at->toDateString())->toBe('2026-08-17')
        ->and($emp->documents_pending_reupload)->toBeTrue();

    // NO second employee record was created for this person.
    expect(Employee::withoutGlobalScopes()->where('id', '!=', $originalId)
        ->where('full_name', $emp->full_name)->count())->toBe(0);

    // Attendance keeps its company_id (A) — never moved.
    expect(Attendance::withoutGlobalScopes()->find($att->id)->company_id)->toBe($this->companyA->id);

    // Two stints: A closed on the transfer date, B open.
    $stints = EmployeeCompanyHistory::where('employee_id', $originalId)->orderBy('started_at')->get();
    expect($stints)->toHaveCount(2)
        ->and($stints[0]->company_id)->toBe($this->companyA->id)
        ->and($stints[0]->ended_at->toDateString())->toBe('2026-08-17')
        ->and($stints[1]->company_id)->toBe($this->companyB->id)
        ->and($stints[1]->ended_at)->toBeNull();

    // Wage: old period closed the day before, kept at A; new open period at B.
    $oldRate = EmployeeWageRate::withoutGlobalScopes()->find($wage->id);
    expect($oldRate->company_id)->toBe($this->companyA->id)
        ->and($oldRate->effective_to->toDateString())->toBe('2026-08-16');
    $openRate = EmployeeWageRate::withoutGlobalScopes()
        ->where('employee_id', $originalId)->whereNull('effective_to')->firstOrFail();
    expect($openRate->company_id)->toBe($this->companyB->id)
        ->and($openRate->effective_from->toDateString())->toBe('2026-08-17');

    $this->assertDatabaseHas('audit_logs', ['action' => 'transferred', 'module' => 'employees']);
});

it('keeps the worker login on the same record (never moved or dropped)', function (): void {
    $user = User::factory()->create(['role' => 'worker']);
    $this->employee->user_id = $user->id;
    $this->employee->save();

    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id, 'transfer_date' => '2026-08-17',
    ])->assertRedirect();

    // Same record still holds the login; it now lives in company B's dropdowns.
    $emp = $this->employee->fresh();
    expect($emp->user_id)->toBe($user->id);
    expect(Employee::withoutGlobalScope(CompanyScope::class)->active()->pluck('id')->all())
        ->toContain($emp->id);
});

it('leaves documents attached to the same record with their original company_id', function (): void {
    $dni = new Document(['category' => 'personal', 'type_key' => 'dni', 'name' => null]);
    $dni->documentable()->associate($this->employee);
    $dni->company_id = $this->companyA->id;
    $dni->version = 1;
    $dni->setAttribute('is_current', true);
    $dni->save();

    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id, 'transfer_date' => '2026-08-17',
    ])->assertRedirect();

    // The single record still owns the doc; it was NOT copied to a new record.
    $docs = Document::withoutGlobalScopes()
        ->where('documentable_type', $this->employee->getMorphClass())
        ->where('documentable_id', $this->employee->id)
        ->get();
    expect($docs)->toHaveCount(1)
        ->and($docs->first()->company_id)->toBe($this->companyA->id);
});

it('ships the employment history for both stints on the employee detail page', function (): void {
    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id, 'transfer_date' => '2026-08-17',
    ]);

    $this->actingAs($this->sa)->get("/employees/{$this->employee->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->has('employmentHistory', 2)
            ->where('employmentHistory.0.is_current', true) // current stint first
        );
});

it('never creates a duplicate record or loses attendance across repeated round-trip transfers', function (): void {
    seedWageRate($this->employee, $this->companyA);
    // Two attendance rows at the original company.
    Attendance::factory()->create(['company_id' => $this->companyA->id, 'employee_id' => $this->employee->id, 'date' => '2026-05-10', 'status' => 'present']);
    Attendance::factory()->create(['company_id' => $this->companyA->id, 'employee_id' => $this->employee->id, 'date' => '2026-05-11', 'status' => 'present']);
    $id = $this->employee->id;
    $uuid = $this->employee->person_uuid;

    // Ping-pong A→B→A→B→A on distinct dates.
    $legs = [
        [$this->companyB->id, '2026-06-01'],
        [$this->companyA->id, '2026-06-02'],
        [$this->companyB->id, '2026-06-03'],
        [$this->companyA->id, '2026-06-04'],
    ];
    foreach ($legs as [$to, $date]) {
        $this->actingAs($this->sa)->post("/employees/{$id}/transfer", [
            'to_company_id' => $to, 'transfer_date' => $date,
        ])->assertRedirect();
    }

    // Still exactly ONE employee record for this person.
    expect(Employee::withoutGlobalScopes()->where('person_uuid', $uuid)->count())->toBe(1);

    // Attendance is intact and untouched — still 2 rows, still at company A.
    $att = Attendance::withoutGlobalScopes()->where('employee_id', $id)->get();
    expect($att)->toHaveCount(2)
        ->and($att->pluck('company_id')->unique()->all())->toBe([$this->companyA->id]);

    // Back at company A, active, with a clean stint chain (initial + 4 legs = 5),
    // exactly one of which is open.
    $emp = Employee::withoutGlobalScopes()->find($id);
    expect($emp->company_id)->toBe($this->companyA->id)->and($emp->active)->toBeTrue();
    $stints = EmployeeCompanyHistory::where('employee_id', $id)->get();
    expect($stints)->toHaveCount(5)
        ->and($stints->whereNull('ended_at')->count())->toBe(1);
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

// Item 9 — the transfer sets documents_pending_reupload, but nothing cleared it,
// so the "re-upload documents" banner stayed forever. The admin can now dismiss
// it once the new company's paperwork is uploaded.
it('clears the documents-pending-reupload reminder after a transfer', function (): void {
    $this->actingAs($this->sa)->post("/employees/{$this->employee->id}/transfer", [
        'to_company_id' => $this->companyB->id, 'transfer_date' => '2026-08-17',
    ])->assertRedirect();

    expect($this->employee->fresh()->documents_pending_reupload)->toBeTrue();

    $this->actingAs($this->sa)
        ->patch("/employees/{$this->employee->id}/documents-reuploaded")
        ->assertRedirect();

    expect($this->employee->fresh()->documents_pending_reupload)->toBeFalse();
});

it('404s when clearing the reminder on another company employee', function (): void {
    $adminA = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
    $foreign = Employee::factory()->forCompany($this->companyB)->create();
    $foreign->documents_pending_reupload = true;
    $foreign->save();

    $this->actingAs($adminA)
        ->patch("/employees/{$foreign->id}/documents-reuploaded")
        ->assertNotFound();

    expect($foreign->fresh()->documents_pending_reupload)->toBeTrue();
});
