<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

it('ships the company departments to the Settings page', function (): void {
    Department::factory()->forCompany($this->company)->create(['name' => 'Civil Works']);

    $this->actingAs($this->admin)->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('departments', 1)
            ->where('departments.0.name', 'Civil Works')
            ->where('departments.0.employee_count', 0));
});

it('creates a department scoped to the acting company', function (): void {
    $this->actingAs($this->admin)->post('/admin/settings/departments', ['name' => 'Electrical'])
        ->assertRedirect();

    $dept = Department::query()->where('name', 'Electrical')->firstOrFail();
    expect($dept->company_id)->toBe($this->company->id)
        ->and($dept->active)->toBeTrue();
});

it('rejects a duplicate department name within a company', function (): void {
    Department::factory()->forCompany($this->company)->create(['name' => 'Plumbing']);

    $this->actingAs($this->admin)->post('/admin/settings/departments', ['name' => 'Plumbing'])
        ->assertSessionHasErrors('name');
});

it('renames a department and syncs assigned employees display string', function (): void {
    $dept = Department::factory()->forCompany($this->company)->create(['name' => 'Painting']);
    $employee = Employee::factory()->forCompany($this->company)->create([
        'department_id' => $dept->id, 'department' => 'Painting',
    ]);

    $this->actingAs($this->admin)->put("/admin/settings/departments/{$dept->id}", [
        'name' => 'Pintura', 'active' => true,
    ])->assertRedirect();

    expect($dept->fresh()->name)->toBe('Pintura')
        ->and($employee->fresh()->department)->toBe('Pintura');
});

it('deletes an unused department', function (): void {
    $dept = Department::factory()->forCompany($this->company)->create();

    $this->actingAs($this->admin)->delete("/admin/settings/departments/{$dept->id}")
        ->assertRedirect();

    expect(Department::query()->find($dept->id))->toBeNull();
});

it('blocks deleting a department that has employees assigned', function (): void {
    $dept = Department::factory()->forCompany($this->company)->create();
    Employee::factory()->forCompany($this->company)->create(['department_id' => $dept->id]);

    $this->actingAs($this->admin)->delete("/admin/settings/departments/{$dept->id}")
        ->assertSessionHasErrors('department');

    expect(Department::query()->find($dept->id))->not->toBeNull();
});

it('cannot manage another company department (404)', function (): void {
    $other = Company::factory()->create();
    $dept = Department::factory()->forCompany($other)->create();

    $this->actingAs($this->admin)->put("/admin/settings/departments/{$dept->id}", [
        'name' => 'Hack', 'active' => true,
    ])->assertNotFound();

    $this->actingAs($this->admin)->delete("/admin/settings/departments/{$dept->id}")
        ->assertNotFound();
});

it('denies department management to a non-admin', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->post('/admin/settings/departments', ['name' => 'X'])
        ->assertForbidden();
});

it('assigns department_id on the employee form and syncs the display string', function (): void {
    $dept = Department::factory()->forCompany($this->company)->create(['name' => 'Drivers']);

    $this->actingAs($this->admin)->post('/employees', [
        'full_name' => 'Conductor Uno',
        'wage_type' => 'daily',
        'department_id' => $dept->id,
        'active' => true,
    ])->assertRedirect();

    $employee = Employee::query()->where('full_name', 'Conductor Uno')->firstOrFail();
    expect($employee->department_id)->toBe($dept->id)
        ->and($employee->department)->toBe('Drivers');
});

it('rejects a cross-company department_id on the employee form', function (): void {
    $other = Company::factory()->create();
    $dept = Department::factory()->forCompany($other)->create();

    $this->actingAs($this->admin)->post('/employees', [
        'full_name' => 'Bad Dept',
        'wage_type' => 'daily',
        'department_id' => $dept->id,
        'active' => true,
    ])->assertSessionHasErrors('department_id');
});

it('filters the employee list by department_id', function (): void {
    $civil = Department::factory()->forCompany($this->company)->create(['name' => 'Civil Works']);
    $elec = Department::factory()->forCompany($this->company)->create(['name' => 'Electrical']);
    Employee::factory()->forCompany($this->company)->create(['department_id' => $civil->id, 'department' => 'Civil Works']);
    Employee::factory()->forCompany($this->company)->create(['department_id' => $elec->id, 'department' => 'Electrical']);

    $this->actingAs($this->admin)->get("/employees?department={$civil->id}")
        ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)
            ->where('employees.data.0.department', 'Civil Works'));
});
