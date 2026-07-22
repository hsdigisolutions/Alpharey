<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

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
        'role' => 'user',
        'locale' => 'es',
        'company_id' => $this->companyB->id, // malicious input — must be ignored
    ])->assertRedirect();

    $user = User::query()->where('email', 'nuevo@alpharey.local')->firstOrFail();

    expect($user->company_id)->toBe($this->companyA->id)
        ->and($user->role)->toBe(UserRole::User);
});

it('forbids company admins from creating other admins', function (): void {
    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Otro Admin',
        'email' => 'otro@alpharey.local',
        'password' => 'secret-123',
        'role' => 'company_admin',
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
        'role' => 'company_admin',
        'locale' => 'es',
    ])->assertRedirect();

    $created = User::query()->where('email', 'admin.empresa@alpharey.local')->firstOrFail();

    expect($created->role)->toBe(UserRole::CompanyAdmin)
        ->and($created->company_id)->toBe($this->companyA->id);
});

it('updates a user including deactivation', function (): void {
    $target = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($this->admin)->put("/admin/users/{$target->id}", [
        'name' => $target->name,
        'email' => $target->email,
        'role' => 'user',
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
        'role' => 'user',
        'locale' => 'es',
        'active' => true,
    ])->assertNotFound();
});

it('blocks editing yourself through the admin panel', function (): void {
    $this->actingAs($this->admin)->put("/admin/users/{$this->admin->id}", [
        'name' => 'Hacked',
        'email' => $this->admin->email,
        'role' => 'user',
        'locale' => 'es',
        'active' => true,
    ])->assertStatus(422);
});

it('blocks company admins from editing other admins', function (): void {
    $otherAdmin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();

    $this->actingAs($this->admin)->put("/admin/users/{$otherAdmin->id}", [
        'name' => 'X',
        'email' => $otherAdmin->email,
        'role' => 'user',
        'locale' => 'es',
        'active' => true,
    ])->assertForbidden();
});

it('denies regular users the user management endpoints', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();

    $this->actingAs($user)->post('/admin/users', [])->assertForbidden();
});
