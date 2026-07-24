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

/**
 * A Super Admin administers the whole group, so the directory must not be
 * clipped to whichever company they happen to be browsing — that hid most of
 * the users from them (reported from the screen).
 */
it('shows a Super Admin every user across all companies', function (): void {
    $sa = User::factory()->superAdmin()->create();
    User::factory()->forCompany($this->companyB)->create();

    $this->actingAs($sa)->withSession(['current_company_id' => $this->companyA->id])
        ->get('/admin/permissions')
        ->assertOk()
        // companyA admin + companyA staff + companyB staff + the SA themselves
        ->assertInertia(fn (Assert $page) => $page->has('users', 4));
});

it('still confines a Company Admin to their own company users', function (): void {
    User::factory()->forCompany($this->companyB)->create();

    $this->actingAs($this->admin)
        ->get('/admin/permissions')
        ->assertOk()
        // only companyA's admin + staff — never companyB
        ->assertInertia(fn (Assert $page) => $page->has('users', 2));
});

// ─── Company assignment ────────────────────────────────────────────────────

it('super admin can assign any company to a manager', function (): void {
    $sa = User::factory()->superAdmin()->create();
    $target = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($sa)
        ->post("/admin/permissions/{$target->id}/companies", ['company_id' => $this->companyB->id])
        ->assertRedirect();

    $this->assertDatabaseHas('user_company', [
        'user_id' => $target->id,
        'company_id' => $this->companyB->id,
        'assigned_by' => $sa->id,
    ]);
});

it('admin can assign their own company to a manager', function (): void {
    $target = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($this->admin)
        ->post("/admin/permissions/{$target->id}/companies", ['company_id' => $this->companyA->id])
        ->assertRedirect();

    $this->assertDatabaseHas('user_company', [
        'user_id' => $target->id,
        'company_id' => $this->companyA->id,
    ]);
});

it('admin cannot assign a different company to a user', function (): void {
    $target = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($this->admin)
        ->post("/admin/permissions/{$target->id}/companies", ['company_id' => $this->companyB->id])
        ->assertForbidden();
});

it('nobody can assign a company to a super admin', function (): void {
    $sa = User::factory()->superAdmin()->create();
    $targetSa = User::factory()->superAdmin()->create();

    $this->actingAs($sa)
        ->post("/admin/permissions/{$targetSa->id}/companies", ['company_id' => $this->companyA->id])
        ->assertForbidden();
});

it('manager cannot access the assign-company endpoint', function (): void {
    $target = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($this->staff)
        ->post("/admin/permissions/{$target->id}/companies", ['company_id' => $this->companyA->id])
        ->assertForbidden();
});

it('super admin can remove a company assignment', function (): void {
    $sa = User::factory()->superAdmin()->create();
    $target = User::factory()->forCompany($this->companyA)->create();
    \Illuminate\Support\Facades\DB::table('user_company')->insert([
        'user_id' => $target->id,
        'company_id' => $this->companyA->id,
        'created_at' => now(),
    ]);

    $this->actingAs($sa)
        ->delete("/admin/permissions/{$target->id}/companies/{$this->companyA->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('user_company', [
        'user_id' => $target->id,
        'company_id' => $this->companyA->id,
    ]);
});

it('removing the primary company sets the next pivot company as primary', function (): void {
    $sa = User::factory()->superAdmin()->create();
    $target = User::factory()->forCompany($this->companyA)->create();

    // Ensure both assignments exist
    \Illuminate\Support\Facades\DB::table('user_company')->insertOrIgnore([
        ['user_id' => $target->id, 'company_id' => $this->companyA->id, 'created_at' => now()],
        ['user_id' => $target->id, 'company_id' => $this->companyB->id, 'created_at' => now()],
    ]);

    $this->actingAs($sa)
        ->delete("/admin/permissions/{$target->id}/companies/{$this->companyA->id}")
        ->assertRedirect();

    expect($target->fresh()->company_id)->toBe($this->companyB->id);
});

it('admin cannot remove a company they do not own', function (): void {
    $target = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($this->admin)
        ->delete("/admin/permissions/{$target->id}/companies/{$this->companyB->id}")
        ->assertForbidden();
});

it('lets a Super Admin save grants for another company user', function (): void {
    $sa = User::factory()->superAdmin()->create();
    $target = User::factory()->forCompany($this->companyB)->create();

    // Browsing company A while editing a user of company B.
    $this->actingAs($sa)->withSession(['current_company_id' => $this->companyA->id])
        ->put("/admin/permissions/{$target->id}", [
            'permissions' => [['module' => 'employees', 'can_view' => true]],
        ])->assertRedirect();

    // The grant is stored against the TARGET's company, not the browsed one.
    $this->assertDatabaseHas('user_module_permissions', [
        'user_id' => $target->id,
        'company_id' => $this->companyB->id,
        'module' => 'employees',
        'can_view' => true,
    ]);
});
