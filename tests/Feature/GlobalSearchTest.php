<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Models\UserModulePermission;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
});

it('groups results by module for a permitted user', function (): void {
    $admin = User::factory()->create(['role' => UserRole::CompanyAdmin, 'company_id' => $this->company->id]);
    Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Antonio Búscame']);
    Project::factory()->create(['company_id' => $this->company->id, 'name' => 'Obra Búscame']);

    $this->actingAs($admin)
        ->getJson('/search?q=Búscame')
        ->assertOk()
        ->assertJsonPath('query', 'Búscame')
        ->assertJsonFragment(['module' => 'employees'])
        ->assertJsonFragment(['module' => 'projects']);
});

it('returns nothing for a term shorter than two characters', function (): void {
    $admin = User::factory()->create(['role' => UserRole::CompanyAdmin, 'company_id' => $this->company->id]);
    Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'A']);

    $this->actingAs($admin)
        ->getJson('/search?q=a')
        ->assertOk()
        ->assertJsonPath('groups', []);
});

it('never searches a module the user cannot view', function (): void {
    // A plain user with ONLY projects.view — employees must not appear.
    $user = User::factory()->create(['role' => UserRole::User, 'company_id' => $this->company->id]);
    UserModulePermission::query()->create([
        'user_id' => $user->id,
        'company_id' => $this->company->id,
        'module' => 'projects',
        'can_view' => true,
    ]);

    Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Secreto Nómina']);
    Project::factory()->create(['company_id' => $this->company->id, 'name' => 'Secreto Obra']);

    $response = $this->actingAs($user)->getJson('/search?q=Secreto')->assertOk();

    $modules = collect($response->json('groups'))->pluck('module');

    expect($modules)->toContain('projects')
        ->and($modules)->not->toContain('employees');
});

it('only reaches the active company for tenant-scoped entities', function (): void {
    $admin = User::factory()->create(['role' => UserRole::CompanyAdmin, 'company_id' => $this->company->id]);
    $other = Company::factory()->create();

    Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Mío Único']);
    Employee::factory()->create(['company_id' => $other->id, 'full_name' => 'Ajeno Único']);

    $response = $this->actingAs($admin)->getJson('/search?q=Único')->assertOk();

    $labels = collect($response->json('groups'))
        ->firstWhere('module', 'employees')['results'] ?? [];

    expect(collect($labels)->pluck('label'))->toContain('Mío Único')
        ->and(collect($labels)->pluck('label'))->not->toContain('Ajeno Único');
});

it('denies the endpoint to a guest', function (): void {
    $this->getJson('/search?q=test')->assertUnauthorized();
});
