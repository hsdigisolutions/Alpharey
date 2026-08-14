<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->sa = User::factory()->superAdmin()->create();
    $this->company = Company::factory()->create();
});

// ── Employee documents ───────────────────────────────────────────────────────

it('saves contacts + expiry on an employee document and ships the smart payload', function (): void {
    $employee = Employee::factory()->for($this->company)->create();

    $this->actingAs($this->sa)->post('/documents', [
        'entity_type' => 'employee', 'entity_id' => $employee->id,
        'category' => 'personal', 'type_key' => 'dni',
        'expiry_date' => '2030-01-01',
        'contacts' => [['name' => 'Ana RRHH', 'role' => 'Recursos Humanos']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc = Document::query()->where('type_key', 'dni')->firstOrFail();
    expect($doc->contacts[0]['name'])->toBe('Ana RRHH')
        ->and($doc->expiry_date?->toDateString())->toBe('2030-01-01');

    $this->get("/employees/{$employee->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Employees/Detail')
        ->where('documents.0.type_key', 'dni')
        ->where('documents.0.contacts.0.name', 'Ana RRHH')
        ->has('documents.0.field_defs')      // generic issue/expiry date fields
        ->has('documents.0.history')
        ->has('documentFieldDefs.dni'));
});

it('rejects bespoke metadata on an employee document (empty whitelist)', function (): void {
    $employee = Employee::factory()->for($this->company)->create();

    $this->actingAs($this->sa)->post('/documents', [
        'entity_type' => 'employee', 'entity_id' => $employee->id,
        'category' => 'personal', 'type_key' => 'dni',
        'metadata' => ['made_up' => 'x'],
    ])->assertSessionHasErrors('metadata.made_up');
});

it('keeps employee document history across versions', function (): void {
    $employee = Employee::factory()->for($this->company)->create();

    $this->actingAs($this->sa);
    $this->post('/documents', ['entity_type' => 'employee', 'entity_id' => $employee->id, 'category' => 'personal', 'type_key' => 'dni'])
        ->assertSessionHasNoErrors();
    $this->post('/documents', ['entity_type' => 'employee', 'entity_id' => $employee->id, 'category' => 'personal', 'type_key' => 'dni'])
        ->assertSessionHasNoErrors();

    $this->get("/employees/{$employee->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('documents.0.version', 2)
        ->has('documents.0.history', 1)
        ->where('documents.0.history.0.version', 1));
});

it('edits contacts on an employee document without changing the version', function (): void {
    $employee = Employee::factory()->for($this->company)->create();

    $this->actingAs($this->sa)->post('/documents', [
        'entity_type' => 'employee', 'entity_id' => $employee->id, 'category' => 'personal', 'type_key' => 'dni',
    ])->assertSessionHasNoErrors();
    $doc = Document::query()->where('type_key', 'dni')->firstOrFail();

    $this->patch("/documents/{$doc->id}/metadata", [
        'contacts' => [['name' => 'Nuevo contacto']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc->refresh();
    expect($doc->version)->toBe(1)->and($doc->contacts[0]['name'])->toBe('Nuevo contacto');
});

// ── Project documents ────────────────────────────────────────────────────────

it('saves contacts on a project document and ships the smart payload', function (): void {
    $project = Project::factory()->for($this->company)->create();

    $this->actingAs($this->sa)->post('/documents', [
        'entity_type' => 'project', 'entity_id' => $project->id,
        'category' => 'project', 'type_key' => 'permit',
        'expiry_date' => '2027-06-01',
        'contacts' => [['name' => 'Ayuntamiento', 'role' => 'Licencias']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $doc = Document::query()->where('type_key', 'permit')->firstOrFail();
    expect($doc->contacts[0]['name'])->toBe('Ayuntamiento');

    $this->get("/projects/{$project->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Projects/Detail')
        ->where('documents.0.type_key', 'permit')
        ->where('documents.0.contacts.0.role', 'Licencias')
        ->has('documents.0.field_defs')
        ->has('documentFieldDefs.permit'));
});
