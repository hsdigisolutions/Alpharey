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
    $admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
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
    $admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
    Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'A']);

    $this->actingAs($admin)
        ->getJson('/search?q=a')
        ->assertOk()
        ->assertJsonPath('groups', []);
});

it('never searches a module the user cannot view', function (): void {
    // A plain user with ONLY projects.view — employees must not appear.
    $user = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);
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
    $admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
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

it('scopes to a single module when module= is given (VSuggestSearch)', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
    Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Antonio Búscame']);
    Project::factory()->create(['company_id' => $this->company->id, 'name' => 'Obra Búscame']);

    $response = $this->actingAs($admin)->getJson('/search?q=Búscame&module=projects')->assertOk();
    $modules = collect($response->json('groups'))->pluck('module');

    // ONLY the requested module runs — the employee match is never queried.
    expect($modules)->toContain('projects')
        ->and($modules)->not->toContain('employees');
});

it('honours the view gate even when a module is requested directly', function (): void {
    // A manager without projects.view asking for module=projects gets nothing —
    // the provider is gated, not merely hidden.
    $user = User::factory()->create(['role' => UserRole::Manager, 'company_id' => $this->company->id]);
    Project::factory()->create(['company_id' => $this->company->id, 'name' => 'Secreto Obra']);

    $this->actingAs($user)
        ->getJson('/search?q=Secreto&module=projects')
        ->assertOk()
        ->assertJsonPath('groups', []);
});

it('returns an empty result for an unknown module', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
    Employee::factory()->create(['company_id' => $this->company->id, 'full_name' => 'Antonio Búscame']);

    $this->actingAs($admin)
        ->getJson('/search?q=Búscame&module=not_a_module')
        ->assertOk()
        ->assertJsonPath('groups', []);
});

it('returns up to eight rows for a single module (vs five on the global bar)', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'company_id' => $this->company->id]);
    Employee::factory()->count(9)->create([
        'company_id' => $this->company->id,
        'full_name' => fn () => 'Zzsuggest '.fake()->unique()->numerify('####'),
    ]);

    $response = $this->actingAs($admin)->getJson('/search?q=Zzsuggest&module=employees')->assertOk();
    $rows = collect($response->json('groups'))->firstWhere('module', 'employees')['results'] ?? [];

    expect($rows)->toHaveCount(8);
});
