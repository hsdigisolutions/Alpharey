<?php

use App\Models\Company;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->forCompany($this->company)->create();
});

it('denies a custom user with no permission rows', function (): void {
    expect(Gate::forUser($this->user)->allows('employees.view'))->toBeFalse();
});

it('allows a custom user with the matching permission row', function (): void {
    UserModulePermission::query()->create([
        'user_id' => $this->user->id,
        'company_id' => $this->company->id,
        'module' => 'employees',
        'can_view' => true,
    ]);

    expect(Gate::forUser($this->user)->allows('employees.view'))->toBeTrue();
});

it('checks each action independently', function (): void {
    UserModulePermission::query()->create([
        'user_id' => $this->user->id,
        'company_id' => $this->company->id,
        'module' => 'payroll',
        'can_view' => true,
        'can_approve' => false,
    ]);

    $gate = Gate::forUser($this->user);

    expect($gate->allows('payroll.view'))->toBeTrue()
        ->and($gate->allows('payroll.approve'))->toBeFalse()
        ->and($gate->allows('payroll.delete'))->toBeFalse();
});

it('applies permission changes immediately', function (): void {
    $permission = UserModulePermission::query()->create([
        'user_id' => $this->user->id,
        'company_id' => $this->company->id,
        'module' => 'invoices',
        'can_export' => false,
    ]);

    expect(Gate::forUser($this->user)->allows('invoices.export'))->toBeFalse();

    $permission->update(['can_export' => true]);

    expect(Gate::forUser($this->user)->allows('invoices.export'))->toBeTrue();
});

it('gives company admins every action within their company', function (): void {
    $admin = User::factory()->companyAdmin()->forCompany($this->company)->create();

    $gate = Gate::forUser($admin);

    expect($gate->allows('employees.delete'))->toBeTrue()
        ->and($gate->allows('payroll.approve'))->toBeTrue()
        ->and($gate->allows('deployments.create'))->toBeTrue();
});

it('gives the super admin everything without permission rows', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();

    expect(Gate::forUser($superAdmin)->allows('payroll.approve'))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('audit.anything'))->toBeTrue();
});

it('denies inactive users regardless of role or rows', function (): void {
    $inactiveAdmin = User::factory()->superAdmin()->inactive()->create();

    UserModulePermission::query()->create([
        'user_id' => $this->user->id,
        'company_id' => $this->company->id,
        'module' => 'employees',
        'can_view' => true,
    ]);
    $this->user->update(['active' => false]);

    expect(Gate::forUser($inactiveAdmin)->allows('employees.view'))->toBeFalse()
        ->and(Gate::forUser($this->user->fresh())->allows('employees.view'))->toBeFalse();
});

it('denies users with no company for company-bound roles', function (): void {
    $orphan = User::factory()->create(['company_id' => null]);

    expect(Gate::forUser($orphan)->allows('employees.view'))->toBeFalse();
});
