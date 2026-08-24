<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Services\Employees\EmployeeService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Change 6 — Project / Employee / Client codes are auto-generated in the legacy
 * format (continuing the sequence, MAX+1), overridable on create, editable
 * later, and unique within their scope.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

// ── nextCode continuation ────────────────────────────────────────────────────

it('continues the legacy VE- employee sequence (MAX+1)', function (): void {
    $this->actingAs($this->admin);
    $seed = Employee::factory()->for($this->company)->create();
    $seed->employee_code = 'VE-0444';
    $seed->save();

    expect(Employee::nextCode())->toBe('VE-445');
});

it('continues the legacy Verto6 project sequence (MAX+1)', function (): void {
    $this->actingAs($this->admin);
    $seed = Project::factory()->for($this->company)->create();
    $seed->code = 'Verto6025';
    $seed->save();

    expect(Project::nextCode())->toBe('Verto6026');
});

it('starts and continues the CLI- client sequence', function (): void {
    expect(Client::nextCode())->toBe('CLI-001');

    $c = Client::factory()->create();
    $c->code = 'CLI-005';
    $c->save();

    expect(Client::nextCode())->toBe('CLI-006');
});

// ── auto-generate vs manual on create ────────────────────────────────────────

it('auto-generates a VE code for a new employee when the field is blank', function (): void {
    $this->actingAs($this->admin);
    $seed = Employee::factory()->for($this->company)->create();
    $seed->employee_code = 'VE-200';
    $seed->save();

    $new = app(EmployeeService::class)->create(['full_name' => 'New Worker', 'wage_type' => 'daily', 'daily_wage' => '50']);

    expect($new->employee_code)->toBe('VE-201');
});

it('accepts a manually entered employee code and lets it be edited', function (): void {
    $this->actingAs($this->admin);
    $service = app(EmployeeService::class);

    $e = $service->create(['full_name' => 'X', 'employee_code' => 'CUSTOM-1', 'wage_type' => 'daily', 'daily_wage' => '50']);
    expect($e->employee_code)->toBe('CUSTOM-1');

    $service->update($e, ['full_name' => 'X', 'employee_code' => 'CUSTOM-2', 'wage_type' => 'daily', 'daily_wage' => '50']);
    expect($e->refresh()->employee_code)->toBe('CUSTOM-2');
});

it('auto-generates a Verto6 code for a new project when blank, and accepts a manual one', function (): void {
    $seed = Project::factory()->for($this->company)->create();
    $seed->code = 'Verto6030';
    $seed->save();

    $this->actingAs($this->admin)->post('/projects', [
        'name' => 'Auto Project', 'status' => 'active', 'priority' => 'medium',
    ])->assertRedirect();
    expect(Project::withoutGlobalScopes()->where('name', 'Auto Project')->first()->code)->toBe('Verto6031');

    $this->actingAs($this->admin)->post('/projects', [
        'code' => 'MYPROJ-9', 'name' => 'Manual Project', 'status' => 'active', 'priority' => 'medium',
    ])->assertRedirect();
    expect(Project::withoutGlobalScopes()->where('name', 'Manual Project')->first()->code)->toBe('MYPROJ-9');
});

// ── uniqueness ───────────────────────────────────────────────────────────────

it('rejects a duplicate client code (422)', function (): void {
    $existing = Client::factory()->create();
    $existing->code = 'CLI-777';
    $existing->save();

    $this->actingAs($this->admin)->post('/clients', [
        'code' => 'CLI-777', 'name' => 'Dupe Co', 'client_type' => 'company',
    ])->assertSessionHasErrors('code');
});

it('auto-generates, then lets a client code be edited, and finds it by search', function (): void {
    $this->actingAs($this->admin)->post('/clients', [
        'name' => 'Searchable Co', 'client_type' => 'company',
    ])->assertRedirect();

    $client = Client::where('name', 'Searchable Co')->firstOrFail();
    expect($client->code)->toStartWith('CLI-');

    // Editable later.
    $this->actingAs($this->admin)->put("/clients/{$client->id}", [
        'code' => 'VIP-1', 'name' => 'Searchable Co', 'client_type' => 'company',
    ])->assertRedirect();
    expect($client->refresh()->code)->toBe('VIP-1');

    // Live search matches the code.
    $this->actingAs($this->admin)->get('/clients?search=VIP-1')->assertInertia(fn (Assert $p) => $p
        ->has('clients.data', 1)
        ->where('clients.data.0.code', 'VIP-1')
    );
});
