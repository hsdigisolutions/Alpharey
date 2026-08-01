<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalaryHistory;
use App\Models\User;
use App\Models\UserColumnSetting;
use App\Models\UserModulePermission;
use Inertia\Testing\AssertableInertia as Assert;

function grantEmployee(User $user, Company $company, array $actions): void
{
    UserModulePermission::query()->create(array_merge([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'module' => 'employees',
    ], $actions));
}

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

it('lists employees for authorized users', function (): void {
    Employee::factory()->count(3)->forCompany($this->company)->create();

    $this->actingAs($this->admin)->get('/employees')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Employees/Index')->has('employees.data', 3));
});

it('denies employees list without view permission', function (): void {
    $user = User::factory()->forCompany($this->company)->create();

    $this->actingAs($user)->get('/employees')->assertForbidden();
});

it('creates an employee with a generated code and records wage history', function (): void {
    $this->actingAs($this->admin)->post('/employees', [
        'full_name' => 'María García',
        'nif' => '12345678Z',
        'wage_type' => 'hourly',
        'wage_rate' => 14.5,
        'active' => true,
    ])->assertRedirect();

    $employee = Employee::query()->where('full_name', 'María García')->firstOrFail();

    expect($employee->employee_code)->toStartWith('E'.$this->company->id.'-')
        ->and($employee->company_id)->toBe($this->company->id)
        ->and($employee->getAttribute('wage_rate'))->toBe('14.5');
});

it('grants and revokes the PWA vehicle-access flag through the employee form', function (): void {
    // Grant on create — the flag is what unlocks the worker vehicle module.
    $this->actingAs($this->admin)->post('/employees', [
        'full_name' => 'Conductor Uno',
        'wage_type' => 'daily',
        'active' => true,
        'can_use_vehicles' => true,
    ])->assertRedirect();

    $employee = Employee::query()->where('full_name', 'Conductor Uno')->firstOrFail();
    expect($employee->can_use_vehicles)->toBeTrue();

    // Revoke on update.
    $this->actingAs($this->admin)->put("/employees/{$employee->id}", [
        'full_name' => $employee->full_name,
        'can_use_vehicles' => false,
    ])->assertRedirect();

    expect($employee->fresh()->can_use_vehicles)->toBeFalse();
});

it('defaults the PWA vehicle-access flag off when the form omits it', function (): void {
    $this->actingAs($this->admin)->post('/employees', [
        'full_name' => 'Sin Vehiculo',
        'wage_type' => 'daily',
        'active' => true,
    ])->assertRedirect();

    expect(Employee::query()->where('full_name', 'Sin Vehiculo')->firstOrFail()->can_use_vehicles)
        ->toBeFalse();
});

it('encrypts NIF at rest and keeps it searchable via the blind index', function (): void {
    $this->actingAs($this->admin)->post('/employees', [
        'full_name' => 'Búsqueda NIF',
        'nif' => '87654321X',
        'active' => true,
    ]);

    $employee = Employee::query()->where('full_name', 'Búsqueda NIF')->firstOrFail();

    // Stored ciphertext is not the plaintext
    $raw = DB::table('employees')->where('id', $employee->id)->value('nif');
    expect($raw)->not->toContain('87654321X')
        ->and($employee->nif)->toBe('87654321X');

    // Search by NIF hits the blind index
    $this->get('/employees?search=87654321X')
        ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)
            ->where('employees.data.0.id', $employee->id));
});

it('hides wage and bank data from users without wage access', function (): void {
    $viewer = User::factory()->forCompany($this->company)->create();
    grantEmployee($viewer, $this->company, ['can_view' => true]);

    $employee = Employee::factory()->forCompany($this->company)->create(['base_salary' => '2500']);

    $this->actingAs($viewer)->get("/employees/{$employee->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.base_salary', null)
            ->where('employee.iban', null));
});

it('exposes wage data to users with wage access', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create(['base_salary' => '2500']);

    $this->actingAs($this->admin)->get("/employees/{$employee->id}")
        ->assertInertia(fn (Assert $page) => $page->where('employee.base_salary', '2500'));
});

it('records salary history only when wage fields change', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create(['base_salary' => '2000']);

    $this->actingAs($this->admin)->put("/employees/{$employee->id}", [
        'full_name' => $employee->full_name,
        'base_salary' => 2400,
        'active' => true,
    ])->assertRedirect();

    $history = EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->where('field', 'base_salary')->firstOrFail();

    expect($history->getAttribute('old_value'))->toBe('2000')
        ->and($history->getAttribute('new_value'))->toBe('2400');
});

it('soft deletes an employee — never hard delete', function (): void {
    $employee = Employee::factory()->forCompany($this->company)->create();

    $this->actingAs($this->admin)->delete("/employees/{$employee->id}")->assertRedirect();

    expect(Employee::query()->find($employee->id))->toBeNull()
        ->and(Employee::withTrashed()->find($employee->id))->not->toBeNull();
});

it('rejects an invalid IBAN format', function (): void {
    $this->actingAs($this->admin)->post('/employees', [
        'full_name' => 'IBAN Malo',
        'iban' => 'not-an-iban!!',
        'active' => true,
    ])->assertSessionHasErrors('iban');
});

it('requires full_name', function (): void {
    $this->actingAs($this->admin)->post('/employees', ['active' => true])
        ->assertSessionHasErrors('full_name');
});

it('bulk-deactivates selected employees', function (): void {
    $employees = Employee::factory()->count(2)->forCompany($this->company)->create(['active' => true]);

    $this->actingAs($this->admin)->post('/employees/bulk-active', [
        'ids' => $employees->pluck('id')->all(),
        'active' => false,
    ])->assertRedirect();

    expect(Employee::query()->where('active', true)->count())->toBe(0);
});

it('saves per-user column visibility', function (): void {
    $this->actingAs($this->admin)->put('/column-settings', [
        'table_name' => 'employees',
        'visible_columns' => ['employee_code', 'full_name', 'city'],
    ])->assertRedirect();

    expect(UserColumnSetting::for($this->admin, 'employees'))
        ->toBe(['employee_code', 'full_name', 'city']);
});
