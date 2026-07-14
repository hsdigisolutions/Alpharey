<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->adminA = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
});

it('cannot view another company employee by ID (404, not 403)', function (): void {
    $foreign = Employee::factory()->forCompany($this->companyB)->create();

    $this->actingAs($this->adminA)->get("/employees/{$foreign->id}")->assertNotFound();
});

it('cannot edit another company employee', function (): void {
    $foreign = Employee::factory()->forCompany($this->companyB)->create();

    $this->actingAs($this->adminA)->put("/employees/{$foreign->id}", [
        'full_name' => 'Hijacked',
        'active' => true,
    ])->assertNotFound();
});

it('cannot delete another company employee', function (): void {
    $foreign = Employee::factory()->forCompany($this->companyB)->create();

    $this->actingAs($this->adminA)->delete("/employees/{$foreign->id}")->assertNotFound();
});

it('lists only own-company employees', function (): void {
    Employee::factory()->count(2)->forCompany($this->companyA)->create();
    Employee::factory()->count(3)->forCompany($this->companyB)->create();

    $this->actingAs($this->adminA)->get('/employees')
        ->assertInertia(fn ($page) => $page->has('employees.data', 2));
});

it('ignores a company_id injected in create input', function (): void {
    $this->actingAs($this->adminA)->post('/employees', [
        'full_name' => 'Sneaky',
        'company_id' => $this->companyB->id, // must be ignored (tenancy rule 1)
        'active' => true,
    ])->assertRedirect();

    $employee = Employee::withoutGlobalScopes()->where('full_name', 'Sneaky')->firstOrFail();

    expect($employee->company_id)->toBe($this->companyA->id);
});

it('does not leak another company employee through bulk update', function (): void {
    $foreign = Employee::factory()->forCompany($this->companyB)->create(['active' => true]);

    $this->actingAs($this->adminA)->post('/employees/bulk-active', [
        'ids' => [$foreign->id],
        'active' => false,
    ])->assertRedirect();

    // The company scope confined the update — foreign row untouched
    expect(Employee::withoutGlobalScopes()->find($foreign->id)->active)->toBeTrue();
});
