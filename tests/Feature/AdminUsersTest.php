<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
});

it('creates a user inside the admin company, ignoring any company_id input', function (): void {
    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Nuevo Usuario',
        'email' => 'nuevo@alpharey.local',
        'password' => 'secret-123',
        'role' => 'manager',
        'locale' => 'es',
        'company_id' => $this->companyB->id, // malicious input — must be ignored
    ])->assertRedirect();

    $user = User::query()->where('email', 'nuevo@alpharey.local')->firstOrFail();

    expect($user->company_id)->toBe($this->companyA->id)
        ->and($user->role)->toBe(UserRole::Manager);

    // The primary company is mirrored on the user_company pivot at creation
    // — a user must never exist outside their own assignment list.
    $this->assertDatabaseHas('user_company', [
        'user_id' => $user->id,
        'company_id' => $this->companyA->id,
        'assigned_by' => $this->admin->id,
    ]);
});

it('locks a super admin role — even another super admin cannot demote them', function (): void {
    $actor = User::factory()->superAdmin()->create();
    $target = User::factory()->superAdmin()->create();

    $this->actingAs($actor)->put("/admin/users/{$target->id}", [
        'name' => $target->name,
        'email' => $target->email,
        'role' => 'manager', // demotion attempt
        'locale' => 'es',
        'active' => true,
    ])->assertSessionHasErrors('role');

    expect($target->fresh()->role)->toBe(UserRole::SuperAdmin);
});

it('still lets a super admin edit another super admin keeping the role', function (): void {
    $actor = User::factory()->superAdmin()->create();
    $target = User::factory()->superAdmin()->create();

    $this->actingAs($actor)->put("/admin/users/{$target->id}", [
        'name' => 'Nombre Corregido',
        'email' => $target->email,
        'role' => 'super_admin',
        'locale' => 'es',
        'active' => true,
    ])->assertRedirect();

    expect($target->fresh()->name)->toBe('Nombre Corregido');
});

it('forbids company admins from creating other admins', function (): void {
    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Otro Admin',
        'email' => 'otro@alpharey.local',
        'password' => 'secret-123',
        'role' => 'admin',
        'locale' => 'es',
    ])->assertSessionHasErrors('role');
});

it('lets the super admin create company admins in the selected company', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)->post("/welcome/{$this->companyA->id}/select");

    $this->post('/admin/users', [
        'name' => 'Admin Empresa',
        'email' => 'admin.empresa@alpharey.local',
        'password' => 'secret-123',
        'role' => 'admin',
        'locale' => 'es',
    ])->assertRedirect();

    $created = User::query()->where('email', 'admin.empresa@alpharey.local')->firstOrFail();

    expect($created->role)->toBe(UserRole::Admin)
        ->and($created->company_id)->toBe($this->companyA->id);
});

it('updates a user including deactivation', function (): void {
    $target = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($this->admin)->put("/admin/users/{$target->id}", [
        'name' => $target->name,
        'email' => $target->email,
        'role' => 'manager',
        'locale' => 'en',
        'active' => false,
    ])->assertRedirect();

    expect($target->fresh()->active)->toBeFalse()
        ->and($target->fresh()->locale)->toBe('en');
});

it('returns 404 for users of another company', function (): void {
    $foreign = User::factory()->forCompany($this->companyB)->create();

    $this->actingAs($this->admin)->put("/admin/users/{$foreign->id}", [
        'name' => 'X',
        'email' => $foreign->email,
        'role' => 'manager',
        'locale' => 'es',
        'active' => true,
    ])->assertNotFound();
});

it('lets a super admin save a user whose primary company differs from the browsed one', function (): void {
    $sa = User::factory()->superAdmin()->create();
    $target = User::factory()->forCompany($this->companyB)->create();

    // Browsing company A while saving a user whose primary is B — the SA
    // directory shows every user, so the save must not 404 (found from the
    // screen: edit + assign company + save bounced to a 404 page).
    $this->actingAs($sa)->post("/welcome/{$this->companyA->id}/select");

    $this->put("/admin/users/{$target->id}", [
        'name' => 'Editado Por SA',
        'email' => $target->email,
        'role' => 'manager',
        'locale' => 'es',
        'active' => true,
    ])->assertRedirect();

    expect($target->fresh()->name)->toBe('Editado Por SA');
});

it('lets an admin edit a manager assigned into their company via the pivot', function (): void {
    // Primary company B, assigned into the admin's company A on the pivot.
    $target = User::factory()->forCompany($this->companyB)->create();
    DB::table('user_company')->insert([
        ['user_id' => $target->id, 'company_id' => $this->companyB->id, 'assigned_by' => null, 'created_at' => now()],
        ['user_id' => $target->id, 'company_id' => $this->companyA->id, 'assigned_by' => null, 'created_at' => now()],
    ]);

    $this->actingAs($this->admin)->put("/admin/users/{$target->id}", [
        'name' => 'Editado Por Admin',
        'email' => $target->email,
        'role' => 'manager',
        'locale' => 'es',
        'active' => true,
    ])->assertRedirect();

    expect($target->fresh()->name)->toBe('Editado Por Admin');
});

it('blocks editing yourself through the admin panel', function (): void {
    $this->actingAs($this->admin)->put("/admin/users/{$this->admin->id}", [
        'name' => 'Hacked',
        'email' => $this->admin->email,
        'role' => 'manager',
        'locale' => 'es',
        'active' => true,
    ])->assertStatus(422);
});

it('blocks company admins from editing other admins', function (): void {
    $otherAdmin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();

    $this->actingAs($this->admin)->put("/admin/users/{$otherAdmin->id}", [
        'name' => 'X',
        'email' => $otherAdmin->email,
        'role' => 'manager',
        'locale' => 'es',
        'active' => true,
    ])->assertForbidden();
});

it('lets a super admin edit another super admin without a company context', function (): void {
    $sa = User::factory()->superAdmin()->create();
    $otherSa = User::factory()->superAdmin()->create();

    // SA has no active company selected — must not 404 when editing another SA.
    $this->actingAs($sa)->put("/admin/users/{$otherSa->id}", [
        'name' => 'Changed Name',
        'email' => $otherSa->email,
        'role' => 'super_admin',
        'locale' => 'en',
        'active' => true,
    ])->assertRedirect();

    expect($otherSa->fresh()->name)->toBe('Changed Name');
});

// NOTE (role rebuild, 2026-07-24): the earlier "SA demotes SA" behavior was
// superseded — an SA role is now LOCKED (a demoted SA has no company and
// lands in limbo; deactivate instead). Pinned by "locks a super admin role"
// above.

it('forbids a company admin from editing a super admin', function (): void {
    $otherSa = User::factory()->superAdmin()->create();

    $this->actingAs($this->admin)->put("/admin/users/{$otherSa->id}", [
        'name' => 'Hacked SA',
        'email' => $otherSa->email,
        'role' => 'manager',
        'locale' => 'es',
        'active' => true,
    ])->assertForbidden();
});

it('denies regular users the user management endpoints', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($user)->post('/admin/users', [])->assertForbidden();
});
