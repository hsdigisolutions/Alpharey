<?php

use App\Models\Company;
use App\Models\Measurement;
use App\Models\Project;
use App\Models\User;
use App\Models\UserModulePermission;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->companyA)->create();
    $this->project = Project::factory()->forCompany($this->companyA)->create();
});

it('lists measurements for authorized users', function (): void {
    Measurement::factory()->count(3)->create(['company_id' => $this->companyA->id, 'project_id' => $this->project->id]);

    $this->actingAs($this->admin)->get('/measurements')
        ->assertInertia(fn (Assert $page) => $page->component('Measurements/Index')->has('measurements.data', 3));
});

it('creates a measurement in the active company', function (): void {
    $this->actingAs($this->admin)->post('/measurements', [
        'project_id' => $this->project->id,
        'date' => '2026-07-01',
        'quantity' => 125.5,
        'unit' => 'm2',
        'measurement_type' => 'area',
    ])->assertRedirect();

    $m = Measurement::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($m->company_id)->toBe($this->companyA->id)
        ->and((float) $m->quantity)->toBe(125.5)
        ->and($m->approved)->toBeFalse();
});

it('approves and un-approves a measurement (feeds billing when approved)', function (): void {
    $m = Measurement::factory()->create(['company_id' => $this->companyA->id, 'project_id' => $this->project->id, 'approved' => false]);

    $this->actingAs($this->admin)->post("/measurements/{$m->id}/approve", ['approved' => true])->assertRedirect();

    $m->refresh();
    expect($m->approved)->toBeTrue()
        ->and($m->approved_by)->toBe($this->admin->id)
        ->and($m->approved_at)->not->toBeNull();

    $this->post("/measurements/{$m->id}/approve", ['approved' => false]);
    expect($m->fresh()->approved)->toBeFalse();
});

it('requires the approve permission to approve', function (): void {
    $user = User::factory()->forCompany($this->companyA)->create();
    UserModulePermission::query()->create([
        'user_id' => $user->id, 'company_id' => $this->companyA->id, 'module' => 'measurements',
        'can_view' => true, 'can_approve' => false,
    ]);
    $m = Measurement::factory()->create(['company_id' => $this->companyA->id, 'project_id' => $this->project->id]);

    $this->actingAs($user)->post("/measurements/{$m->id}/approve", ['approved' => true])->assertForbidden();
});

it('cannot touch another company measurement (404)', function (): void {
    $foreign = Measurement::factory()->create(['company_id' => $this->companyB->id]);

    $this->actingAs($this->admin)->post("/measurements/{$foreign->id}/approve", ['approved' => true])->assertNotFound();
});

it('validates required fields', function (): void {
    $this->actingAs($this->admin)->post('/measurements', ['measurement_type' => 'nope'])
        ->assertSessionHasErrors(['project_id', 'date', 'quantity', 'measurement_type']);
});
