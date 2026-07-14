<?php

use App\Models\Company;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
    $this->staff = User::factory()->forCompany($this->companyA)->create();
});

it('is reachable by admins only', function (): void {
    $this->actingAs($this->admin)->get('/admin/permissions')->assertOk();

    $this->actingAs($this->staff)->get('/admin/permissions')->assertForbidden();
});

it('lists only users of the admin company', function (): void {
    User::factory()->forCompany($this->companyB)->create(['name' => 'Foreign User']);

    $this->actingAs($this->admin)->get('/admin/permissions')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Permissions')
            ->has('users', 2) // the admin + own staff, never company B
            ->has('matrix', 18));
});

it('redirects a super admin without a selected company to welcome', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)->get('/admin/permissions')->assertRedirect('/welcome');
});

it('saves permission rows and applies them immediately', function (): void {
    expect(Gate::forUser($this->staff)->allows('employees.view'))->toBeFalse();

    $this->actingAs($this->admin)->put("/admin/permissions/{$this->staff->id}", [
        'permissions' => [
            ['module' => 'employees', 'can_view' => true, 'can_create' => true],
            ['module' => 'payroll', 'can_view' => true, 'can_approve' => true],
        ],
    ])->assertRedirect();

    $gate = Gate::forUser($this->staff->fresh());

    expect($gate->allows('employees.view'))->toBeTrue()
        ->and($gate->allows('employees.create'))->toBeTrue()
        ->and($gate->allows('employees.delete'))->toBeFalse()
        ->and($gate->allows('payroll.approve'))->toBeTrue();
});

it('forces non-applicable actions off whatever the client sends', function (): void {
    // Upload does not apply to the employees module (spec matrix)
    $this->actingAs($this->admin)->put("/admin/permissions/{$this->staff->id}", [
        'permissions' => [
            ['module' => 'employees', 'can_view' => true, 'can_upload' => true],
        ],
    ])->assertRedirect();

    $row = UserModulePermission::query()
        ->where('user_id', $this->staff->id)
        ->where('module', 'employees')
        ->firstOrFail();

    expect($row->can_upload)->toBeFalse()
        ->and($row->can_view)->toBeTrue();
});

it('returns 404 for users of another company', function (): void {
    $foreign = User::factory()->forCompany($this->companyB)->create();

    $this->actingAs($this->admin)->put("/admin/permissions/{$foreign->id}", [
        'permissions' => [['module' => 'employees', 'can_view' => true]],
    ])->assertNotFound();
});

it('rejects matrix rows for admin-role users', function (): void {
    $otherAdmin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();

    $this->actingAs($this->admin)->put("/admin/permissions/{$otherAdmin->id}", [
        'permissions' => [['module' => 'employees', 'can_view' => true]],
    ])->assertStatus(422);
});

it('serves copy-from permissions for the copy preset', function (): void {
    UserModulePermission::query()->create([
        'user_id' => $this->staff->id,
        'company_id' => $this->companyA->id,
        'module' => 'clients',
        'can_view' => true,
        'can_export' => true,
    ]);

    $target = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($this->admin)
        ->get("/admin/permissions?user={$target->id}&copy_from={$this->staff->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('copySourcePermissions.clients.view', true)
            ->where('copySourcePermissions.clients.export', true));
});

it('records who granted the permissions', function (): void {
    $this->actingAs($this->admin)->put("/admin/permissions/{$this->staff->id}", [
        'permissions' => [['module' => 'employees', 'can_view' => true]],
    ]);

    expect(UserModulePermission::query()->where('user_id', $this->staff->id)->value('granted_by'))
        ->toBe($this->admin->id);
});
